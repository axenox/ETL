<?php

namespace axenox\ETL\Common;

use axenox\ETL\Common\AbstractETLPrototype;
use axenox\ETL\Common\OpenAPI\OpenAPI3;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\DataSheetDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\FormulaFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Widgets\DebugMessage;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Tasks\HttpTaskInterface;
use axenox\ETL\Interfaces\OpenApiFacadeInterface;

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
    protected function getAPISchema(TaskInterface $task) : APISchemaInterface
    {
        foreach ($this->taskSchemas as $taskSchema) {
            if ($taskSchema['task'] === $task) {
                return $taskSchema['model'];
            }
        }

        if (! ($task instanceof HttpTaskInterface)) {
            throw new InvalidArgumentException('Cannot use OpenAPI flow steps with non-HTTP tasks!');
        }
        
        $facade = $task->getFacade();
        if ($facade === null || ! ($facade instanceof OpenApiFacadeInterface)) {
            throw new InvalidArgumentException('Cannot use OpenAPI flow steps with non-OpenAPI facades!');
        }
        
        $json = $facade->getOpenApiDef($task->getHttpRequest());
        if ($json === null) {
            throw new InvalidArgumentException('Cannot load OpenAPI definition from HTTP task!');
        }
        $model = new OpenAPI3($this->getWorkbench(), $json);
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
     * Adds additional data provided by the ´additional_rows´ config within the step to given row into the given datasheet.
     *
     * @param DataSheetInterface $dataSheet
     * @param array $placeholder
     * @param array $row
     * @param int $rowIndex
     * @return void
     */
    protected function addRowToDataSheetWithAdditionalColumns(DataSheetInterface $dataSheet, array $placeholder, array $row, int $rowIndex) : void
    {
        $additionalColumn = $this->getAdditionalColumn();
        // add row data to placeholders so they can be used in formulars
        $placeholder = array_merge($placeholder, $row);
        $dataSheet->addRow($row);
        foreach ($additionalColumn as $column) {
            $value = $column['value'];
            switch (true) {
                case str_contains($value, '='):
                    // replace placeholder to ensure static if possible
                    $value = StringDataType::replacePlaceholders($value, $placeholder, false);
                    $expression = FormulaFactory::createFromString($this->getWorkbench(), $value);
                    $dataSheet->setCellValue($column['attribute_alias'], $rowIndex,  $expression->evaluate($dataSheet, $rowIndex));
                    break;
                case empty(StringDataType::findPlaceholders($value)) === false:
                    $dataSheet->setCellValue($column['attribute_alias'], $rowIndex,  StringDataType::replacePlaceholders($value, $placeholder));
                    break;
                default:
                    $dataSheet->setCellValue($column['attribute_alias'], $rowIndex, $value);
            }
        }
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
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanGenerateDebugWidgets::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        if ($this->toSheet !== null) {
            $debug_widget = $this->toSheet->createDebugWidget($debug_widget);
        }
        return $debug_widget;
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

    protected function createBaseDataSheet(array $placeholders = []) : DataSheetInterface
    {
        if (null !== $uxon = $this->getBaseDataSheetUxon()) {
            if (! empty($placeholders)) {
                $json = $uxon->toJson();
                $json = StringDataType::replacePlaceholders($json, $placeholders);
                $uxon = UxonObject::fromJson($json);
            } 
            $ds = DataSheetFactory::createFromUxon($this->getWorkbench(), $uxon, $this->getToObject());
        } else {
            $ds = DataSheetFactory::createFromObject($this->getToObject());
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