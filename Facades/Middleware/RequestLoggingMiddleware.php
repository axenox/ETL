<?php
namespace axenox\ETL\Facades\Middleware;

use axenox\ETL\DataTypes\WebRequestStatusDataType;
use exface\Core\Behaviors\TimeStampingBehavior;
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
            'body_file__CONTENTS' => $request->getBody()->__toString(),
            'http_content_type' => implode(';', $request->getHeader('Content-Type'))]);
        
        $logData->dataCreate(false);
        $logData->getFilters()->addConditionFromColumnValues($logData->getUidColumn());
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
        $this->dataUpdateWithoutTimeStamping($taskData, false);

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
            $this->logResponse($request, $response, $e ? $e->getMessage() : '');
        }
        
        $logData = $this->logDataRequest->extractSystemColumns();
        $logData->setCellValue('status', 0, WebRequestStatusDataType::ERROR);

        if ($e !== null) {
            $logData->setCellValue('error_message', 0, $e->getMessage());
            $logData->setCellValue('error_logid', 0, $e->getId());
        }

        try {
            $this->finished = true;
            $this->dataUpdateWithoutTimeStamping($logData, false);
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
        $this->logResponse($request, $response, $output);
        
        $logData = $this->logDataRequest->extractSystemColumns();

        $logData->setCellValue('status', 0, WebRequestStatusDataType::DONE);
        $this->dataUpdateWithoutTimeStamping($logData, false);

        $this->logDataRequest->merge($logData);
        $this->finished = true;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @param string                 $output
     * @return void
     */
    protected function logResponse(ServerRequestInterface $request, ResponseInterface $response, string $output) : void
    {
        $logData = $this->getLogDataResponse($request)->extractSystemColumns();
        
        $logData->setCellValue('webservice_request', 0, $this->logDataRequest->getRow()[$this->logDataRequest->getUidColumnName()]);
        $logData->setCellValue('http_response_code', 0, $response->getStatusCode());
        $logData->setCellValue('response_header', 0, json_encode($response->getHeaders()));
        $logData->setCellValue('body_file__CONTENTS', 0, $response->getBody()->__toString());
        $logData->setCellValue('result_text', 0, $output);

        $this->dataUpdateWithoutTimeStamping($logData, false);
        $this->logDataResponse->merge($logData);
    }

    /**
     * Call `$sheet->dataUpdate()`, while suppressing TimeStampingBehavior on the updated object.
     * 
     * NOTE: Does not affect sub-sheets!
     * 
     * @param DataSheetInterface $sheet
     * @param bool               $createIfUidNotFound
     * @return void
     */
    protected function dataUpdateWithoutTimeStamping(DataSheetInterface $sheet, bool $createIfUidNotFound = false) : void
    {
        $object = $sheet->getMetaObject();
        $stateChanged = TimeStampingBehavior::disableForObject($object);
        
        $sheet->dataUpdate($createIfUidNotFound);
        
        if($stateChanged) {
            TimeStampingBehavior::enableForObject($object);
        }
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
            
            $requestData = $this->getLogDataRequest($request);
            if($requestData) {
                $requestUid = $requestData->getUidColumn()->getValues();
                $dataSheet->getFilters()->addConditionFromValueArray(
                    'webservice_request',
                    $requestUid
                );
                
                $dataSheet->dataRead();
                
                if($dataSheet->countRows() === 0) {
                    $dataSheet->addRow([
                        'webservice_request' => $requestUid[0],
                        'http_response_code' => 200
                    ]);
                    $dataSheet->dataCreate();
                    $dataSheet->dataRead();
                }
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
