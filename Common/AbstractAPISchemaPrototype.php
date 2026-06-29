<?php

namespace axenox\ETL\Common;

use axenox\ETL\ETLPrototypes\ExcelApiToDataSheet;
use axenox\ETL\Factories\APISchemaFactory;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\ApiSchemaFacadeInterface;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\DataSheetDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\ConditionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Tasks\HttpTaskInterface;

/**
 * Base class for automated flow steps, that use standardized API schemas like OpenAPI or OData
 */
abstract class AbstractAPISchemaPrototype extends AbstractETLPrototype
{
    protected $toSheet = null;

    private $baseDataSheetUxon = null;

    private $taskSchemas = [];
    
    /**
     * 
     * @param TaskInterface $task
     * @throws InvalidArgumentException
     * @return string
     */
    protected function getAPISchema(ETLStepDataInterface $stepData) : APISchemaInterface
    {
        $task = $stepData->getTask();
        foreach ($this->taskSchemas as $taskSchema) {
            if ($taskSchema['task'] === $task) {
                return $taskSchema['model'];
            }
        }
        
        $facade = $task->getFacade();
        
        // Load model via facade.
        if ($facade instanceof ApiSchemaFacadeInterface && $task instanceof HttpTaskInterface) {
            $model = $facade->getApiSchemaForRequest($task->getHttpRequest());
        } 
        // Load model directly.
        else {
            $alias = null;
            $version = null;
            
            if($this instanceof ExcelApiToDataSheet) {
                $alias = $this->getWebserviceAlias();
                $version = $this->getWebserviceVersion();
            }

            $additionalFilters = [];
            if ($alias === null) {
                $additionalFilters[] = ConditionFactory::createFromExpressionString(
                    MetaObjectFactory::createFromString($this->getWorkbench(), 'axenox.ETL.webservice'),
                    'webservice_flow__flow__flow_run__UID',
                    $stepData->getFlowRunUid(),
                    '=='
                );
            }
            
            $model = APISchemaFactory::loadAPISchema(
                $this->getWorkbench(),
                null,
                $version,
                $alias,
                null,
                $additionalFilters
            );
        }

        
        $this->taskSchemas[] = [
            'task' => $task,
            'model' => $model
        ];
        
        return $model;
    }

    /**
     * @param ETLStepDataInterface $stepData
     * @param array $requestedColumns
     * @return DataSheetInterface
     */
    protected function loadRequestData(ETLStepDataInterface $stepData, array $requestedColumns): DataSheetInterface
    {
        $requestLogData = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice_request');
        $requestLogData->getColumns()->addFromSystemAttributes();
        $requestLogData->getColumns()->addMultiple($requestedColumns);
        $requestLogData->getFilters()->addConditionFromString('flow_run', $stepData->getFlowRunUid());
        $requestLogData->dataRead();

        if ($requestLogData->countRows() > 1) {
            throw new InvalidArgumentException('Ambiguous web requests!');
        }

        return $requestLogData;
    }

    /**
     * Datasheets from OpenApi JSON data cannot have relations.
     * There are only used for dynamic formulars like =Lookup()
     * and must be removed when the input data has been processed into the datasheet.
     *
     * @param DataSheetInterface $dataSheet
     * @return void
     */
    protected function removeRelationColumns(DataSheetInterface $dataSheet): DataSheetInterface
    {
        foreach ($dataSheet->getColumns() as $column) {
            if ($column->getExpressionObj()->isMetaAttribute() === false) {
                continue;
            }

            if ($column->getAttribute()->isRelated() === true && ! $column->getDataType() instanceof DataSheetDataType) {
                $dataSheet->getColumns()->remove($column);
            }
        }
        return $dataSheet;
    }

    /**
     * Customize the data sheet used in this step by adding custom columns, specifying filters, etc.
     * 
     * @uxon-property base_data_sheet
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheet
     * @uxon-template {"columns": [{"attribute_alias": ""}]}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return AbstractAPISchemaPrototype
     */
    protected function setBaseDataSheet(UxonObject $uxon) : AbstractAPISchemaPrototype
    {
        $this->baseDataSheetUxon = $uxon;
        return $this;
    }

    protected function createBaseDataSheet(MetaObjectInterface $baseObject, array $placeholders = []) : DataSheetInterface
    {
        if (null !== $uxon = $this->getBaseDataSheetUxon()) {
            if (! empty($placeholders)) {
                $json = $uxon->toJson();
                $json = StringDataType::replacePlaceholders($json, $placeholders, false);
                $uxon = UxonObject::fromJson($json);
            }
            $ds = DataSheetFactory::createFromUxon($this->getWorkbench(), $uxon, $baseObject);
        } else {
            $ds = DataSheetFactory::createFromObject($baseObject);
        }
        return $ds;
    }

    /**
     * 
     * @return UxonObject|null
     */
    protected function getBaseDataSheetUxon() : ?UxonObject
    {
        return $this->baseDataSheetUxon;
    }
}