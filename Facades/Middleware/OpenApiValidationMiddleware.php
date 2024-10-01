<?php
namespace axenox\ETL\Facades\Middleware;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use exface\Core\DataTypes\JsonDataType;
use League\OpenAPIValidation\PSR7\Exception\Validation\AddressValidationFailed;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidParameter;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use axenox\ETL\Interfaces\OpenApiFacadeInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Exceptions\DataTypes\JsonSchemaValidationError;
use cebe\openapi\spec\OpenApi;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use cebe\openapi\ReferenceContext;
use exface\Core\Exceptions\Facades\HttpBadRequestError;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use GuzzleHttp\Exception\BadResponseException;
use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * This middleware adds request and response validation to facades implementing OpenApiFacadeInterface
 * 
 * @author andrej.kabachnik
 *
 */
final class OpenApiValidationMiddleware implements MiddlewareInterface
{

    private OpenApiFacadeInterface $facade;
    
    private array $excludePatterns = [];
    
    public function __construct(OpenApiFacadeInterface $facade, array $excludePatterns = [])
    {
        $this->facade = $facade;
        $this->excludePatterns = $excludePatterns;
    }

    /**
     * Process an incoming server request.
     *
     * Processes an incoming server request in order to produce a response.
     * If unable to produce the response itself, it may delegate to the provided
     * request handler to do so.
     *
     * @throws TypeErrorException if request does not contain a valid openapi
     * @throws UnresolvableReferenceException if references within the openapi could not be resolved
     * @throws JsonSchemaValidationError if request  or response did not match json schema
     * @throws HttpBadRequestError if request contained an unknown validation error
     * @throws BadResponseException if response contained an unknown validation error
     * @throws \Flow\JSONPath\JSONPathException if json path for schema produces an error
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->excludePatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return $handler->handle($request);
            }
        }
        
        $swaggerArray = $this->facade->getOpenApiJson($request);
        if ($swaggerArray === null) {
            return $handler->handle($request);
        }
        
        $schema = new OpenApi($swaggerArray);
        $schema->resolveReferences(new ReferenceContext($schema, "/"));
        
        $builder = (new ValidatorBuilder())->fromSchema($schema);
        $requestValidator = $builder->getRequestValidator();
        
        // 1. Validate request
        try {
            $matchedOASOperation = $requestValidator->validate($request);
        } catch (ValidationFailed $exception) {
            $prev = $exception->getPrevious();
            if ($prev) {
                $msg = $prev->getMessage();
                switch (true) {
                    case $prev instanceof SchemaMismatch && str_contains($exception->getMessage(), 'Body'):
                        if ($this->isVerbose($request) && $this->hasJsonBody($request)) {
                            try {
                                $schema = $this->facade->getRequestBodySchemaForCurrentRoute($request);
                                $json = $request->getBody()->__toString();
                                JsonDataType::validateJsonSchema($json, $schema);
                            } catch (JsonSchemaValidationError $e) {
                                $errors = [
                                    'error' => $exception->getMessage(),
                                    'details' => $e->getErrors()
                                ];
                                $eDetails = new JsonSchemaValidationError($errors, 'Invalid request body', null, null, $json);
                                throw new HttpBadRequestError($request, $e2->getMessage(), null, $eDetails);
                            }
                        }

                        throw new HttpBadRequestError($request, $exception->getMessage(), null, $exception);
                    case $prev instanceof InvalidParameter:
                        $schemaError = $prev->getPrevious();
                        $context = 'Invalid request parameter';
                        $msg = $prev->getMessage() . '. ' . $schemaError->getMessage();
                        throw new HttpBadRequestError($request, $context . $msg, null, $exception);
                }
            }

            throw new HttpBadRequestError($request, $exception->getMessage(), null, $exception);
        }
        
        // 2. Process request
        $response = $handler->handle($request);
        
        // 3. Validate response
        $responseValidator = $builder->getResponseValidator();
        $statusCode = $response->getStatusCode();
        // just validate successful response if there is any - 204 has none as RFC9110 defines
        if ($statusCode >= 200 && $statusCode < 300 && $statusCode != 204) {
            try {
                $responseValidator->validate($matchedOASOperation, $response);
            } catch (ValidationFailed $exception) {
                $message = $exception instanceof AddressValidationFailed ? $exception->getVerboseMessage() : $exception->getMessage();
                if ($this->isVerbose($request) && $this->hasJsonBody($response)) {
                    try {
                        $schema = $this->facade->getResponseBodySchemaForCurrentRoute($request, $statusCode);
                        $json = $response->getBody()->__toString();
                        JsonDataType::validateJsonSchema($json, $schema);
                    } catch (JsonSchemaValidationError $e) {
                        $errors = [
                            'error' => $message,
                            'details' => $e->getErrors()
                        ];
                        throw (new JsonSchemaValidationError($errors, 'Invalid response body', null, null, $json))->setStatusCode(500);
                    }
                }
                throw (new JsonSchemaValidationError(['error' => $message, 'details' => $message], 'Invalid response body', null, null, $response->getBody()->__toString()))->setStatusCode(500);
            }
        }
        
        return $response;
    }
    
    protected function hasJsonBody(ServerRequestInterface|ResponseInterface $response) : bool
    {
        $contentType = implode(';', $response->getHeader('Content-Type'));
        return stripos($contentType, 'json') !== false;
    }
    
    protected function getWorkbench() : WorkbenchInterface
    {
        return $this->facade->getWorkbench();
    }
    
    protected function isVerbose(ServerRequestInterface $request) : bool
    {
        $verbose = $this->facade->getVerbose();
        if (is_bool($verbose) === true) {
            return $verbose;
        } else {
            return $request->getQueryParams()[$this->verbose] === 'true';
        }
    }
}
