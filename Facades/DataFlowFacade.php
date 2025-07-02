<?php
namespace axenox\ETL\Facades;

use axenox\ETL\Common\AbstractOpenApiPrototype;
use axenox\ETL\Common\OpenAPI\OpenAPI3;
use axenox\ETL\Facades\Middleware\RequestLoggingMiddleware;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\ApiSchemaFacadeInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\UnavailableError;
use Flow\JSONPath\JSONPathException;
use GuzzleHttp\Psr7\Response;
use Intervention\Image\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use axenox\ETL\Actions\RunETLFlow;
use exface\Core\CommonLogic\Selectors\ActionSelector;
use exface\Core\CommonLogic\Tasks\HttpTask;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Facades\FacadeRoutingError;
use exface\Core\Exceptions\DataTypes\JsonSchemaValidationError;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\Facades\AbstractHttpFacade\Middleware\RouteConfigLoader;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use axenox\ETL\Interfaces\OpenApiFacadeInterface;
use axenox\ETL\Facades\Middleware\OpenApiValidationMiddleware;
use axenox\ETL\Facades\Middleware\OpenApiMiddleware;
use axenox\ETL\Facades\Middleware\SwaggerUiMiddleware;
use Flow\JSONPath\JSONPath;
use axenox\ETL\Facades\Middleware\RouteAuthenticationMiddleware;
use exface\Core\Exceptions\DataSheets\DataNotFoundError;
use stdClass;


/**
 * 
 * 
 * @author Andrej Kabachnik
 * 
 */
class DataFlowFacade extends AbstractHttpFacade implements OpenApiFacadeInterface, ApiSchemaFacadeInterface
{
    // TODO: move all OpenApiFacadeInterface methods to OpenAPI3 schema class

	const REQUEST_ATTRIBUTE_NAME_ROUTE = 'route';
	private $openApiCache = [];
    private RequestLoggingMiddleware $loggingMiddleware;
    private $verbose = null;
    private $routePath = null;

    /**
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::createResponse()
	 */
	protected function createResponse(ServerRequestInterface $request): ResponseInterface
	{
	    $headers = $this->buildHeadersCommon();
        $response = null;

        $routeModel = $request->getAttribute(self::REQUEST_ATTRIBUTE_NAME_ROUTE);

        if ((bool)$routeModel['enabled'] === false) {
            // return Service Unavailable if related data flow is not running
            throw new UnavailableError('Dataflow inactive.');
        }

        $routePath = RouteConfigLoader::getRoutePath($request);

    	// process flow
		$routeUID = $routeModel['UID'];
		$flowAlias = $this->getFlowAlias($routeUID, $routePath);
		$flowRunUID = RunETLFlow::generateFlowRunUid();
        $this->loggingMiddleware->logRequestProcessing($request, $routeUID, $flowRunUID);
	    $flowResult = $this->runFlow($flowAlias, $request); // flow data update
		$flowOutput = $flowResult->getMessage();
        $responseData = $this->loadResponseData($request);

        if ($responseData->countRows() === 1) {
			$body = $this->createRequestResponseBody($responseData, $request, $headers, $routeModel, $routePath);
			$response = new Response(200, $headers, $body);
		}

		if ($response === null) {
			$response = new Response(204, $headers, '', reason: 'No Content');
		}

        $this->loggingMiddleware->logRequestDone($request, $flowOutput, $response);
		return $response;
	}

	/**
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
	 */
	public function getUrlRouteDefault(): string
	{
		return 'api/dataflow';
	}

    /**
     * @param string $flowAlias
     * @param ServerRequestInterface $request
     * @param DataSheetInterface $requestLogData
     * @return ResultInterface
     * @throws \Throwable
     */
	protected function runFlow(string $flowAlias, ServerRequestInterface $request): ResultInterface
	{
        $taskData = $this->loggingMiddleware->getTaskData($request);
		$task = new HttpTask($this->getWorkbench(), $this, $request);
		$task->setInputData($taskData);

		$actionSelector = new ActionSelector($this->getWorkbench(), RunETLFlow::class);
		/* @var $action \axenox\ETL\Actions\RunETLFlow */
		$action = ActionFactory::createFromPrototype($actionSelector, $this->getApp());
		$action->setMetaObject($taskData->getMetaObject());
		$action->setFlowAlias($flowAlias);
		$action->setInputFlowRunUid('flow_run');

		$result = $action->handle($task);
		return $result;
	}

    /**
     *
     * /**
     * @param DataSheetInterface     $responseData
     * @param ServerRequestInterface $request
     * @param array                  $headers
     * @param array                  $routeModel
     * @param string                 $routePath
     * @return string|null
     * @throws JSONPathException
     */
	private function createRequestResponseBody(
		DataSheetInterface     $responseData,
		ServerRequestInterface $request,
		array                  &$headers,
		array                  $routeModel,
		string                 $routePath) : ?string
	{
        // body already created in step
        $responseHeader = $responseData->getRow()['response_header'];
        if ($responseHeader  !== null) {
            $headers = array_merge($headers, json_decode($responseHeader, true));
            return $responseData->getRow()['body_file__CONTENTS'];
        }

        $flowResponse = null;
        $body = $responseData->getRow()['body_file__CONTENTS'];
        if ($body !== null) {
		    $flowResponse = json_decode($body, true);
        }
		
		// load response model from swagger
		$methodType = strtolower($request->getMethod());
		$schemaClass = $routeModel['type__schema_class'];
        $schema = new $schemaClass($this->getWorkbench(), $routeModel['swagger_json']);
        $responseModel = $schema->getResponseDataTemplate(
            $routePath,
            $methodType,
            $routeModel['swagger_json']
        );
        // TODO move all this response creation logic to the API schemas! For now it only
        // Works with OpenAPI3 classes anyhow.
		if ($responseModel === null && empty($responseModel)) {
            return null;
		}

        $headers['Content-Type'] = 'application/json';
		// merge flow response into empty model
        if (empty($flowResponse) === false){
            $body = array_merge($responseModel, $flowResponse);
        } else {
            $body = $responseModel;
        }

		return json_encode($body);
	}

    protected function getFlowAlias(string $routeUid, string $routePath) : string
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice_flow');
        $ds->getColumns()->addMultiple(['webservice', 'flow__alias', 'route']);
        $ds->getFilters()->addConditionFromString('webservice', $routeUid);
        $ds->dataRead();

        $alias = null;
        $rows = $ds->getRows();
        foreach ($rows as $row){
            // Compare routes without leading slashes because people will copy these slashes from
            // the swagger UI and paste them into the route field in the webservice config.
            if (strcasecmp(ltrim($row['route'], '/'), ltrim($routePath,'/')) === 0) {
                $alias = $row['flow__alias'];
                return $alias;
            }
        }

        if ($alias === null && count($rows) === 1){
            return $rows[0]['flow__alias'];
        } else {
            $msg = 'webservice route `' . $routePath . '` (route UID `' . $routeUid . '`)';
            if (count($rows) === 0) {
                $msg = 'No data flow found for ' . $msg;
            } else {
                $msg = 'Multiple data flows found for ' . $msg;
            }
            throw new DataNotFoundError($ds, $msg);
        }
    }

	/**
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getMiddleware()
	 */
	protected function getMiddleware(): array
	{
	    // Use local version of JSONPathLexer with edit to
	    // Make sure to require BEFORE the JSONPath classes are loaded, so that the custom lexer replaces
	    // the one shipped with the library.
	    require_once '..' . DIRECTORY_SEPARATOR
	    . '..' . DIRECTORY_SEPARATOR
	    . 'axenox' . DIRECTORY_SEPARATOR
	    . 'etl' . DIRECTORY_SEPARATOR
	    . 'Common' . DIRECTORY_SEPARATOR
	    . 'JSONPath' . DIRECTORY_SEPARATOR
	    . 'JSONPathLexer.php';
	    
		$ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice');
		$ds->getColumns()->addMultiple(
			['UID', 'local_url', 'full_url', 'version', 'type__schema_class', 'swagger_json', 'config_uxon', 'enabled']);
		$ds->dataRead();

        $excludePattern = ['/.*swaggerui$/', '/.*openapi\\.json$/'];
        $loggingMiddleware = new RequestLoggingMiddleware($this, $excludePattern);
        $this->loggingMiddleware = $loggingMiddleware;

		$middleware = parent::getMiddleware();
		$middleware[] = new RouteConfigLoader($this, $ds, 'local_url', 'config_uxon','version', $this->getUrlRouteDefault(), self::REQUEST_ATTRIBUTE_NAME_ROUTE );
		$middleware[] = new RouteAuthenticationMiddleware($this, [], true);
		$middleware[] = $loggingMiddleware;
        $middleware[] = new OpenApiValidationMiddleware($this, $excludePattern);
		$middleware[] = new OpenApiMiddleware($this, $this->buildHeadersCommon(), '/.*openapi\\.json$/');
		$middleware[] = new SwaggerUiMiddleware($this, $this->buildHeadersCommon(), '/.*swaggerui$/', 'openapi.json');
		
		return $middleware;
	}

	/**
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::createResponseFromError()
	 */
	protected function createResponseFromError(\Throwable $exception, ServerRequestInterface $request = null): ResponseInterface
	{
		$code = $exception->getStatusCode();
		$headers = $this->buildHeadersCommon();

        /*
		if ($this->getWorkbench()
			->getSecurity()
			->getAuthenticatedToken()
			->isAnonymous()) {
            $response = new Response($code, $headers);
            // Don't log anonymous requests to avoid flooding the request log
            return $response;
		}
        */

        $headers['Content-Type'] = 'application/json';
        if ($exception instanceof JsonSchemaValidationError) {
            $errorData = json_encode($exception->getFormattedErrors());
		} else {
            $errorData = json_encode(['Error' => [
                'Message' => $exception->getMessage(),
                'Log-Id' => $exception->getId()]
            ]);
        }

		$response = new Response($code, $headers, $errorData);

        $this->loggingMiddleware->logRequestFailed($request, $exception, $response);
        return $response;
	}

    /**
     * @param ServerRequestInterface $request
     * @return DataSheetInterface
     */
	private function loadResponseData(ServerRequestInterface $request) : DataSheetInterface
	{
        $responseData = $this->loggingMiddleware->getLogDataResponse($request);
		
        $responseData->getColumns()->addMultiple([
            'response_header',
            'body_file__CONTENTS'
        ]);
        $responseData->getFilters()->addConditionFromColumnValues($responseData->getUidColumn());
		$responseData->dataRead();
        
        return $responseData;
	}

	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::buildHeadersCommon()
	 */
	protected function buildHeadersCommon(): array
	{
		$facadeHeaders = array_filter($this->getConfig()
			->getOption('FACADE.HEADERS.COMMON')
			->toArray());
		$commonHeaders = parent::buildHeadersCommon();
		return array_merge($commonHeaders, $facadeHeaders);
	}
	
	/**
	 * @deprecated move to OpenAPI3 schema class
	 * @see \axenox\ETL\Interfaces\OpenApiFacadeInterface::getOpenApiJson()
	 */
	public function getOpenApiJson(ServerRequestInterface $request): ?array
	{
		$path = $request->getUri()->getPath();
		if (array_key_exists($path, $this->openApiCache)) {
			return $this->openApiCache[$path];
		}
		
        $schema = $this->getApiSchemaForRequest($request);
        if (! $schema instanceof OpenAPI3) {
            throw new FacadeRoutingError('Cannot get OpenAPI.json from non-OpenAPI route!');
        }
        $version = $request->getAttribute(self::REQUEST_ATTRIBUTE_NAME_ROUTE)['version'];
        if ($version === null) {

        }

        $basePath = $this->getUrlRouteDefault();
        $routePath = StringDataType::substringAfter($path, $basePath, $path);
        $webserviceBase = StringDataType::substringBefore($routePath, '/', '', true, true) . '/';
        $basePath .= '/' . ltrim($webserviceBase, "/");

        $json = $schema->publish($basePath);
		$openApiJson = json_decode($json, true);
		$this->openApiCache[$path] = $openApiJson;
		return $openApiJson;
	}

    /**
	 * @deprecated move to OpenAPI3 schema class
	 * @see \axenox\ETL\Interfaces\OpenApiFacadeInterface::getOpenApiDef()
	 */
	public function getOpenApiDef(ServerRequestInterface $request): ?string
	{
	    return $this->getApiSchemaForRequest($request)->__toString();
	}

    /**
     * {@inheritDoc}
     * @see ApiSchemaFacadeInterface::getApiSchemaForRequest()
     */
    public function getApiSchemaForRequest(ServerRequestInterface $request) : ?APISchemaInterface
    {
        $routeData = $request->getAttribute(self::REQUEST_ATTRIBUTE_NAME_ROUTE);
        if (empty($routeData)) {
            throw new FacadeRoutingError('No route data found in request!');
        }
        $json = $routeData['swagger_json'];
        if ($json === null || $json === '') {
            return null;
        }

        $schemaClass = $routeData['type__schema_class'];
        $version = $routeData['version'];
        return new $schemaClass($this->getWorkbench(), $json, $version);
    }

    /**
     *
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\OpenApiFacadeInterface::getRequestBodySchemaForCurrentRoute()
     */
    public function getRequestBodySchemaForCurrentRoute(ServerRequestInterface $request): object
    {
        $jsonPath = '$.paths.[#routePath#].[#methodType#].requestBody';
        $contentType = $request->getHeader('Content-Type')[0];
        return $this->getJsonSchemaFromOpenApiByRef($request, $jsonPath, $contentType);
    }

    /**
     *
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\OpenApiFacadeInterface::getResponseBodySchemaForCurrentRoute()
     */
    public function getResponseBodySchemaForCurrentRoute(ServerRequestInterface $request, int $responseCode): object
    {
        $jsonPath = '$.paths.[#routePath#].[#methodType#].responses.' . $responseCode;
        $contentType = $request->getHeader('accept')[0];
        return $this->getJsonSchemaFromOpenApiByRef($request, $jsonPath, $contentType);
    }

    /**
     * @deprecated move to OpenAPI3 schema class
	 * @see \axenox\ETL\Interfaces\OpenApiFacadeInterface::getJsonSchemaFromOpenApiByRef()
     */
    public function getJsonSchemaFromOpenApiByRef(ServerRequestInterface $request, string $jsonPath, string $contentType): object
    {
        /** @var \axenox\ETL\Interfaces\APISchema\APISchemaInterface $openApiSchema */
        $openApiSchema = $this->openApiCache[$request->getUri()->getPath()];
        // FIXME this always throws an error. It gets called by the OpenApiValidationMiddleware
        // when that finds an error and wants details.
        $openApiSchema->getRouteRouteForRequest($request);
        $jsonPath = $this->findSchemaPathInOpenApiJson($request, $jsonPath, $contentType);
        $schema = $this->findJsonDataByRef($openApiSchema, $jsonPath);
        if ($schema === null) {
            throw new InvalidArgumentException('Could not find schema with given json path in OpenApi.'
            . ' Json path: ' . $jsonPath);
        }

        $schema = is_array($schema) ? json_decode(json_encode($schema)) : $schema;
        return $this->convertNullableToNullType($schema);
    }

    /**
     *
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\OpenApiFacadeInterface::convertNullableToNullType()
     */
    public function convertNullableToNullType(object $schema) : object
    {
        if ($schema instanceof StdClass === false) {
            throw new InvalidArgumentException('');
        }

        foreach ($schema as $objectPart) {
            if (is_object($objectPart) === false) {
                continue;
            }

            if (property_exists($objectPart, 'nullable') && $objectPart->nullable === true) {
                $type = $objectPart->type;
                $objectPart->type = [ $type,  'null'];
            } else {
                $this->convertNullableToNullType($objectPart);
            }
        }

        return $schema;
    }


    /**
     * @deprecated move to OpenAPI3 schema class
     * @see \axenox\ETL\Interfaces\OpenApiFacadeInterface::findSchemaPathInOpenApiJson()
     */
    public function findSchemaPathInOpenApiJson(ServerRequestInterface $request, string $jsonPath, string $contentType): string
    {
        $path = $this->getRoutePath($request);
        $routePath = rtrim(strstr($path, '/'), '/');
        $methodType = strtolower($request->getMethod());

        $jsonPath .= '.content.[#ContentType#].schema';
        return str_replace(
            ['[#routePath#]', '[#methodType#]', '[#ContentType#]'],
            [$routePath, $methodType, $contentType],
            $jsonPath);
    }

    /**
     * @param array|null $openApiSchema
     * @param string $jsonPath
     * @return mixed|null
     * @throws \Flow\JSONPath\JSONPathException
     */
    public function findJsonDataByRef(?array $openApiSchema, string $jsonPath): mixed
    {
        $jsonPathFinder = new JSONPath($openApiSchema);
        $refSchema = $jsonPathFinder->find($jsonPath)->getData()[0] ?? null;
        if ($refSchema != null) {
            $refSchema = str_replace('#', '$', $refSchema['$ref']);
            $refSchema = str_replace('/', '.', $refSchema);
            return $jsonPathFinder->find($refSchema)->getData()[0] ?? null;
        }

        return null;
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     */
    public function getRoutePath(ServerRequestInterface $request, array $routeModel = null): string
    {
        if ($this->routePath !== null) {
            return $this->routePath;
        }

        $path = $request->getUri()->getPath();
        $routeComponents = AbstractOpenApiPrototype::extractUrlComponents($path);
        $this->routePath = $routeComponents['route'];
        return $this->routePath;
    }

    /**
     * Configure the validation options for this facade.
     * Set verbose true if the validation should produce a detailed multiple result error message on error.
     * Set to false if you want the request to be fast, resulting in a short and single error message on error.
     *
     * @uxon-property validation
     * @uxon-type object
     * @uxon-template {"verbose": false}
     *
     * @param UxonObject $uxon
     * @return AbstractHttpFacade
     */
    protected function setValidation(UxonObject $uxon) : AbstractHttpFacade
    {
        if (($verbose = $uxon->getProperty('verbose')) !== null) {
            $this->verbose = $verbose;
        }

        return $this;
    }

    public function isVerbose(): ?bool
    {
        return $this->verbose;
    }
}
