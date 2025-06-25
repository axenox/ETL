<?php
namespace axenox\ETL\ETLPrototypes;

use axenox\ETL\Common\AbstractAPISchemaPrototype;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use exface\Core\CommonLogic\DataSheets\DataColumn;
use exface\Core\CommonLogic\Model\ConditionGroup;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use exface\Core\DataTypes\StringDataType;
use axenox\ETL\Common\IncrementalEtlStepResult;
use exface\Core\Interfaces\Tasks\HttpTaskInterface;
use exface\Core\Widgets\DebugMessage;
use axenox\ETL\Events\Flow\OnBeforeETLStepRun;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use Flow\JSONPath\JSONPathException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Auto-create responses for annotated APIs (like OpenAPI) using object and attribute annotations in the API schema
 * 
 * ## Annotations
 * 
 * ´´´
 * {
 *     "Object": {
 *          "type": "object",
 *          "x-object-alias": "alias",
 *          "properties: {
 *               "Id": {
 *                   "type": "string",
 *                   "x-attribute-alias": "UID"
 *               }
 *          }
 *     }
 * }
 *
 * ´´´
 *
 * Attributes can be defined within the OpenAPI like this:
 * 
 * ´´´
 *  {
 *     "Id": {
 *         "type": "int",
 *         "x-attribute-alias": "UID"
 *     },
 *     "someThing": {
 *         "x-attribute-alias": "FORW_RELATION__OTHER_ATTR"
 *     },
 *     "someThing": {
 *         "x-attribute-alias": "BACKW_RELATION__OTHER_ATTR2:SUM"
 *     },
 *     "someThing": {
 *         "x-attribute-alias": "=CONCAT(ATTR1, ' ', ATTR2)"
 *     }
 *  }
 *
 * ´´´
 *
 * The to-object MUST be defined within the response schema of the route to the step!
 * e.g. with multiple structural concepts
 * 
 * ```
 * "responses": {
 *   "200": {
 *     "description": "Erfolgreiche Abfrage",
 *       "content": {
 *         "application/json": {
 *           "schema": {
 *             "type": "object",
 *             "properties": {
 *               "tranchen": {
 *                 "type": "object",
 *                 "properties": {
 *                   "rows": {
 *                     "type": "array",
 *                     "items": {
 *                       "$ref": "#/components/schemas/Object" // only for the example within the ui
 *                     },
 *                     "x-object-alias": "full.namespace.object" // filled with step data
 *                   }
 *                 }
 *               },
 *             "page_limit": {
 *               "type": "array",
 *               "items": {
 *                 "type": "object",
 *                 "properties": {
 *                   "offset": {
 *                     "type": "integer",
 *                     "nullable": true,
 *                     "x-placeholder": "[#~parameter:limit#]" // filled with placeholder values
 *                   }
 *                 }
 *               }
 *             },
 *            "page_offset": {
 *               "type": "integer",
 *               "nullable": true,
 *               "x-placeholder": "[#~parameter:offset#]" // filled with placeholder values
 *              }
 *            }
 *          }
 *        }
 *      }
 *    }
 *  }
 * 
 * ```
 *
 * As you see in the response schema example, you can use placeholders for page_limit and offset, as well as
 * values within the response.
 *
 * @author Andrej Kabachnik, Miriam Seitz
 */
class DataSheetToJsonApi extends AbstractAPISchemaPrototype
{
    private $rowLimit = null;
    private $rowOffset = 0;
    private $filters = null;
    private $schemaName = null;
    private $baseSheet = null;


    /**
     *
     * {@inheritDoc}
     * @throws JSONPathException
     * @see \axenox\ETL\Interfaces\ETLStepInterface::run()
     */
    public function run(ETLStepDataInterface $stepData) : \Generator
    {
    	$stepRunUid = $stepData->getStepRunUid();
    	$placeholders = $this->getPlaceholders($stepData);
    	$result = new IncrementalEtlStepResult($stepRunUid);
        $stepTask = $stepData->getTask();

        if ($stepTask instanceof HttpTaskInterface === false){
            throw new InvalidArgumentException('Http request needed to process OpenApi definitions! `' . get_class($stepTask) . '` received instead.');
        }

        $baseSheet = $this->createBaseDataSheet($placeholders);
        if ($limit = $this->getRowLimit($placeholders)) {
            $baseSheet->setRowsLimit($limit);
        }
        $baseSheet->setAutoCount(false);

        $this->baseSheet = $baseSheet;
        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeETLStepRun($this));

        $offset = $this->getRowOffset($placeholders) ?? 0;
        $fromSheet = $baseSheet->copy();
        $fromSheet->setRowsOffset($offset);
        if ((! $fromSheet->hasSorters()) && $fromSheet->getMetaObject()->hasUidAttribute()) {
            $fromSheet->getSorters()->addFromString($fromSheet->getMetaObject()->getUidAttributeAlias());
        }

        yield 'Reading '
            . ($limit ? 'rows ' . ($offset+1) . ' - ' . ($offset+$limit) : 'all rows')
            . ' requested in OpenApi definition';

        $apiSchema = $this->getAPISchema($stepData->getTask());
        $fromObjectSchema = $apiSchema->getObjectSchema($fromSheet->getMetaObject(), $this->getSchemaName());
        $requestedColumns = $this->addColumnsFromSchema($fromObjectSchema, $fromSheet);
        
        $fromSheet->dataRead();
        foreach ($fromSheet->getColumns() as $column) {
            // remove data that was not requested but loaded anyway
            if (array_key_exists($column->getName(), $requestedColumns) === false) {
                $fromSheet->getColumns()->remove($column);
            }
        }

        $content = [];
        // enforce from sheet defined data types
        foreach ($fromSheet->getColumns() as $column) {
            $colName = $column->getName();
            $values = $column->getValuesNormalized();
            foreach ($values as $i => $val) {
                $content[$i][$colName] = $val;
            }
        }

        $requestData = $this->loadRequestData($stepData, []);
        $responseData = $this->loadResponseData($requestData->getRow()['oid'], ['response_body', 'response_header']);
        $this->updateRequestData($responseData, $fromObjectSchema, $content, $placeholders);

        return $result->setProcessedRowsCounter(count($content));
    }

    /**
     * @param array $fromObjectSchema
     * @return DataColumn[]
     */
    protected function addColumnsFromSchema(APIObjectSchemaInterface $fromObjectSchema, DataSheetInterface $fromSheet) : array
    {
        $requestedColumns = [];

        foreach ($fromObjectSchema->getProperties() as $propName => $propSchema) {
            if (! $propSchema->isBoundToMetamodel()) {
                continue;
            }

            switch (true) {
                // calculations like ´=Sum(attribute_alias) o. =Format(attribute_alias)´
                case $propSchema->isBoundToCalculation():
                    $col = $fromSheet->getColumns()->addFromExpression($propSchema->getCalculationExpression(), $propName);
                    break;
                // attribute alias like ´attribute_alias´ or ´related_attribute__LABEL:LIST´
                case $propSchema->isBoundToAttribute():
                    $col = $fromSheet->getColumns()->addFromExpression($propSchema->getAttributeAlias(), $propName);
                    break;
            }

            if ($col !== null) {
                $requestedColumns[$propName] = $col;
            }
        }

        return $requestedColumns;
    }

    /**
     * @param array $jsonSchema
     * @param array $newContent
     * @param string $objectAlias
     * @param array $placeholders
     * @return array
     */
    protected function createBodyFromSchema(array $jsonSchema, array $newContent, string $objectAlias, array $placeholders) : array
    {
        if ($jsonSchema['type'] == 'array') {
            $result = $this->createBodyFromSchema($jsonSchema['items'], $newContent, $objectAlias, $placeholders);

            if (empty($result) === false) {
                $body[] = $result;
            }
        }

        if ($jsonSchema['type'] == 'object') {
            foreach ($jsonSchema['properties'] as $propertyName => $propertyValue) {
                switch (true) {
                    case array_key_exists('x-object-alias', $propertyValue) && $propertyValue['x-object-alias'] === $objectAlias:
                        $body[$propertyName] = $newContent;
                        break;
                    case array_key_exists('x-placeholder', $propertyValue):
                        $value = StringDataType::replacePlaceholders($propertyValue['x-placeholder'], $placeholders, false);

                        switch (true) {
                            case empty($value):
                                $value = null;
                                break;
                            case ($propertyValue['type'] === 'integer'):
                                $value = (int)$value;
                                break;
                            case ($propertyValue['type'] === 'boolean'):
                                $value = (bool)$value;
                                break;
                        }

                        $body[$propertyName] = $value;
                        break;
                    case $propertyValue['type'] === 'array':
                    case $propertyValue['type'] === 'object':
                        $body[$propertyName] = $this->createBodyFromSchema($propertyValue, $newContent, $objectAlias, $placeholders);
                }
            }
        }

        if ($body === null) {
            return [];
        }

        return $body;
    }

    /**
     * @param DataSheetInterface       $responseData
     * @param APIObjectSchemaInterface $objectSchema
     * @param array                    $rows
     * @param array                    $placeholders
     * @return void
     */
    protected function updateRequestData(
        DataSheetInterface       $responseData,
        APIObjectSchemaInterface $objectSchema,
        array                    $rows,
        array                    $placeholders): void
    {
        $responseSchema = $objectSchema->getJsonSchema();
        $currentBody = json_decode($responseData->getCellValue('response_body', 0), true);
        $newBody = $this->createBodyFromSchema($responseSchema, $rows, $objectSchema->getMetaObject()->getAliasWithNamespace(), $placeholders);
        $newBody = $currentBody === null ? $newBody : $this->deepMerge($currentBody, $newBody);
        $responseData->setCellValue('response_header', 0, 'application/json');
        $responseData->setCellValue('response_body', 0, json_encode($newBody));
        $responseData->dataUpdate();
    }

    /**
     * @param array $first
     * @param array $second
     * @return array
     */
    protected function deepMerge(array $first, array $second): array
    {
        $result = [];
        foreach ($first as $key => $entry) {
            if (is_array($entry) && array_key_exists($key, $second)){
                $result[$key] = array_merge($entry, $second[$key]);
            } else if (array_key_exists($key, $second)) {
                $result[$key] = $second[$key];
            } else {
                $result[$key] = $entry;
            }
        }

        return $result;
    }

    /**
     *
     * @param $placeholders
     * @return int|NULL
     */
    protected function getRowLimit($placeholders) : ?int
    {
        if (is_string($this->rowLimit)){
            $value = StringDataType::replacePlaceholders($this->rowLimit, $placeholders, false);
            return empty($value) ? null : $value;
        }

        return $this->rowLimit;
    }

    /**
     * Number of rows to read at once - no limit if set to NULL.
     *
     * Use this parameter if the data of the from-object has
     * large amounts of data at once.
     *
     * Use ´row_offset´ to read the next chunk.
     *
     * @uxon-property row_limit
     * @uxon-type int|null
     *
     * @param $numberOfRows
     * @return DataSheetToJsonApi
     */
    protected function setRowLimit($numberOfRows) : DataSheetToJsonApi
    {
        $this->rowLimit = $numberOfRows;
        return $this;
    }

    protected function getRowOffset($placeholders) : ?int
    {
        if (is_string($this->rowOffset)){
            $value = StringDataType::replacePlaceholders($this->rowOffset, $placeholders, false);
            return empty($value) ? null : $value;
        }

        return $this->rowOffset;
    }

    /**
     * Start position from which to read the from-sheet - no offset if set to NULL.
     *
     * Use this parameter if the data of the from-object has
     * large amounts of data at once.
     *
     * Use ´row_limit´ to define the chunk size.
     *
     * @uxon-property row_offset
     * @uxon-type int|null
     *
     * @param $startPosition
     * @return DataSheetToJsonApi
     */
    protected function setRowOffset($startPosition) : DataSheetToJsonApi
    {
        $this->rowOffset = $startPosition;
        return $this;
    }


    /**
     * Condition group to filter the data when reading from the data source.
     *
     * @uxon-property filters
     * @uxon-type \exface\Core\CommonLogic\UxonObject
     * @uxon-template {"filters":{"operator": "AND","conditions":[{"expression": "","comparator": "=","value": ""}]}}
     * @return DataSheetToJsonApi
     */
    public function setFilters(UxonObject $filters) : DataSheetToJsonApi
    {
        $this->filters = $filters;
        return $this;
    }

    protected function getBaseDataSheetUxon(): ?UxonObject
    {
        $uxon = parent::getBaseDataSheetUxon();
        if ($this->filters !== null) {
            $uxon->setProperty('filters', $this->filters);
        }
        return $uxon;
    }

    /**
     * Define the name of the schema for this specific step.
     * If null, it will try to find the attribute alias within the OpenApi definition.
     *
     * @uxon-property schema_name
     * @uxon-type string
     *
     * @param string $schemaName
     * @return OpenApiJsonToDataSheet
     */
    protected function setSchemaName(string $schemaName) : DataSheetToJsonApi
    {
        $this->schemaName = $schemaName;
        return $this;
    }

    protected function getSchemaName() : ?string
    {
        return $this->schemaName;
    }

    /**
     *
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::parseResult()
     */
    public static function parseResult(string $stepRunUid, string $resultData = null): ETLStepResultInterface
    {
        return new IncrementalEtlStepResult($stepRunUid, $resultData);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanGenerateDebugWidgets::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget, ?ETLStepDataInterface $stepData = null)
    {
        $debug_widget = parent::createDebugWidget($debug_widget, $stepData);
        if ($this->baseSheet !== null) {
            $debug_widget = $this->baseSheet->createDebugWidget($debug_widget);
        }
        return $debug_widget;
    }

    /**
     *
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::isIncremental()
     */
    public function isIncremental(): bool
    {
        return false;
    }
}