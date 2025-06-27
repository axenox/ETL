<?php
namespace axenox\ETL\Facades\Middleware;

use axenox\ETL\DataTypes\WebRequestStatusDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use axenox\ETL\Interfaces\OpenApiFacadeInterface;

/**
 * This middleware handels all logging with a webservice request.
 * 
 * @author miriam.seitz
 *
 */
final class RequestLoggingMiddleware implements MiddlewareInterface
{
    private $facade = null;
    private $excludePatterns = [];
    private $finished = false;

    private DataSheetInterface|null $logDataRequest = null;
    private DataSheetInterface|null $logDataResponse = null;

    private DataSheetInterface|null $taskData = null;

    /**
     * @param OpenApiFacadeInterface $facade
     * @param array $excludePatterns
     */
    public function __construct(OpenApiFacadeInterface $facade,  array $excludePatterns = [])
    {
        $this->excludePatterns = $excludePatterns;
        $this->facade = $facade;
    }


    /**
     * Process an incoming server request.
     *
     * Processes an incoming server request in order to produce a response.
     * If unable to produce the response itself, it may delegate to the provided
     * request handler to do so.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->excludePatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return $handler->handle($request);
            }
        }

        $this->logRequestReceived($request);
        $response = $handler->handle($request);
        if ($this->finished === false) {
            if ($response->getStatusCode() < 300) {
                $this->logRequestDone($request, '', $response);
            } else {
                $this->logRequestFailed($request, null, $response);
            }
        }
        return $response;
    }

    /**
     * @param ServerRequestInterface $request
     * @return void
     */
    public function logRequestReceived(
        ServerRequestInterface $request): void
    {
        $logData = DataSheetFactory::createFromObjectIdOrAlias(
            $this->facade->getWorkbench(),
            'axenox.ETL.webservice_request');
        
        $logData->addRow([
            'status' => WebRequestStatusDataType::RECEIVED,
            'url' => $request->getUri()->__toString(),
            'url_path' => StringDataType::substringAfter(
                $request->getUri()->getPath(),
                $this->facade->getUrlRouteDefault() . '/',
                $request->getUri()->getPath()),
            'http_method' => $request->getMethod(),
            'http_headers' => JsonDataType::encodeJson($request->getHeaders()),
            'body_file' => $request->getBody()->__toString(),
            'http_content_type' => implode(';', $request->getHeader('Content-Type'))]);

        $logData->dataCreate(false);
        $this->logDataRequest = $logData;
    }

    /**
     * @param ServerRequestInterface $request
     * @param string $routeUID
     * @param string $flowRunUID
     * @return void
     */
    public function logRequestProcessing(
        ServerRequestInterface $request,
        string $routeUID,
        string $flowRunUID): void
    {
        // create request log if missing
        if ($this->logDataRequest === null) {
            $this->logRequestReceived($request);
        }

        $taskData = $this->logDataRequest->extractSystemColumns();
        
        $taskData->setCellValue('route', 0, $routeUID);
        $taskData->setCellValue('status', 0, WebRequestStatusDataType::PROCESSING);
        $taskData->setCellValue('flow_run', 0, $flowRunUID);
        
        $taskData->dataUpdate(false);
        $this->taskData = $taskData;
        $this->logDataRequest->merge($taskData);
    }

    /**
     * @param ServerRequestInterface $request
     * @param \Throwable|NULL $e
     * @param ResponseInterface|null $response
     * @return void
     */
    public function logRequestFailed(
        ServerRequestInterface $request,
        \Throwable $e = null,
        ResponseInterface $response = null): void
    {
        // do not log errors in request log prior to a valid request
        if ($this->logDataRequest === null) {
            return;
        }

        if ($response !== null) {
            $this->logResponse($response);
        }
        
        $logData = $this->logDataRequest->extractSystemColumns();
        $logData->setCellValue('status', 0, WebRequestStatusDataType::ERROR);

        if ($e !== null) {
            $logData->setCellValue('error_message', 0, $e->getMessage());
            $logData->setCellValue('error_logid', 0, $e->getId());
            $logData->setCellValue('http_response_code', 0, $e->getStatusCode());
        }

        try {
            $this->finished = true;
            $logData->dataUpdate(false);
            $this->logDataRequest->merge($logData);
        } catch (\Throwable $eUpdate) {
            // Do not throw an error if the logging fails, just log it to the main log.
            // The web service should still output the regular error result even if
            // something went wrong when logging!
            $this->facade->getWorkbench()->getLogger()->logException($eUpdate);
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param string $output
     * @param ResponseInterface $response
     * @return void
     */
    public function logRequestDone(
        ServerRequestInterface $request,
        string $output,
        ResponseInterface $response): void
    {
        $this->logResponse($response, $output);
        
        $logData = $this->logDataRequest->extractSystemColumns();
        
        $logData->setCellValue('status', 0, WebRequestStatusDataType::DONE);
        
        $logData->dataUpdate(false);
        $this->logDataRequest->merge($logData);
        $this->finished = true;
    }

    /**
     * @param ResponseInterface $response
     * @param string            $output
     * @return void
     */
    protected function logResponse(ResponseInterface $response, string $output) : void
    {
        $logData = $this->logDataResponse->extractSystemColumns();
        
        $logData->setCellValue('webservice_request', 0, $this->logDataRequest->getRow()[$this->logDataRequest->getUidColumnName()]);
        $logData->setCellValue('http_response_code', 0, $response->getStatusCode());
        $logData->setCellValue('response_header', 0, json_encode($response->getHeaders()));
        $logData->setCellValue('body_file', 0, $response->getBody()->__toString());
        $logData->setCellValue('result_text', 0, $output);
        $logData->setCellValue('http_response_code', 0, $response->getStatusCode());

        $logData->dataUpdate();
        $this->logDataResponse->merge($logData);
    }
    
    /**
     * @param ServerRequestInterface $request
     * @return DataSheetInterface|null
     */
    public function getLogDataRequest(ServerRequestInterface $request) : ?DataSheetInterface
    {
        return $this->logDataRequest;
    }
    
    public function getLogDataResponse(ServerRequestInterface $request) : ?DataSheetInterface
    {
        if($this->logDataResponse === null) {
            $dataSheet = DataSheetFactory::createFromObjectIdOrAlias(
                $this->facade->getWorkbench(),
                'axenox.ETL.webservice_response');
            
            $dataSheet->getColumns()->addFromSystemAttributes();
            $dataSheet->getColumns()->addFromExpression('webservice_request');
            
            if($this->getLogDataRequest($request)) {
                $dataSheet->getFilters()->addConditionFromValueArray(
                    'webservice_request',
                    $this->getLogDataRequest($request)->getUidColumn()->getValues()
                );
                $dataSheet->dataRead();
            } 
            
            $this->logDataResponse = $dataSheet;
        }
        
        
        return $this->logDataResponse;
    }

    /**
     * @param ServerRequestInterface $request
     * @return DataSheetInterface|null
     */
    public function getTaskData(ServerRequestInterface $request) : ?DataSheetInterface
    {
        return $this->taskData;
    }
}
