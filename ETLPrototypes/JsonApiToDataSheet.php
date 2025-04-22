<?php
namespace axenox\ETL\ETLPrototypes;

use axenox\ETL\Common\AbstractAPISchemaPrototype;
use axenox\ETL\Common\StepNote;
use axenox\ETL\Common\Traits\PreventDuplicatesStepTrait;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use exface\Core\Factories\DataSheetMapperFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSheets\DataSheetMapperInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Tasks\HttpTaskInterface;
use axenox\ETL\Events\Flow\OnBeforeETLStepRun;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use Flow\JSONPath\JSONPathException;
use axenox\ETL\Common\UxonEtlStepResult;
use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * Read data received through an annotated API (like OpenAPI) using object and attribute annotations
 * 
 * ## Annotations
 * 
 * ´´´
 * {
 *     "Object": {
 *          "type": "object",
 *          "x-object-alias": "alias",
 *          "properties: {
 *              "Id" {
 *                  "type": "string",
 *                  "x-attribute-alias": "UID"
 *              }
 *          }
 *     }
 * }
 *
 * ´´´
 *
 * Only use direct Attribute aliases in the definition and never relation paths or formulars!
 * e.g `"x-attribute-alias": "Objekt_ID"`
 * If you want to link objects, use the id/uid in the original attribute.
 * e.g. `"x-attribute-alias": "Request"` -> '0x11EFBD3FD893913ABD3F005056BEF75D'
 *
 * The from-object HAS to be defined within the request schema of the route to the step!
 * e.g. with multiple structural concepts
 * 
 * ```
 * "requestBody": {
 *   "description": "Die zu importierenden Daten im Json format.",
 *   "required": true,
 *   "content": {
 *     "application/json": {
 *       "schema": {
 *         "type": "object",
 *         "properties": {
 *           "Objekte": {
 *             "type": "array",
 *             "items": {
 *               "$ref": "#/components/schemas/Object"
 *             },
 *             "x-object-alias": "full.namespace.Object"
 *           }
 *         }
 *       }
 *     }
 *   }
 * }
 * 
 * ```
 *
 * ## Customizing the data sheet with placeholders
 * 
 * Using `base_data_sheet` you can customize the data sheet, that is going to be used by adding
 * filters, sorters, aggregators, etc. from placeholders available in the flow step.
 * 
 * ### Example: save additional information about the flow run in a staging table
 * 
 * ```
 * {
 *  "base_data_sheet": {
 *      "columns": [
 *          {
 *              "attribute_alias": "ETLFlowRunUID",
 *              "value": "[#flow_run_uid#]"
 *          },
 *          {
 *              "attribute_alias": "RequestId",
 *              "value": "=Lookup('UID', 'axenox.ETL.webservice_request', 'flow_run = [#flow_run_uid#]')"
 *          },
 *          {
 *              "attribute_alias": "Betreiber",
 *              "value": "SuedLink"
 *          }
 *      ]
 * }
 * 
 * ```
 *
 * @author Andrej Kabachnik, Miriam Seitz
 */
class JsonApiToDataSheet extends AbstractAPISchemaPrototype
{
    use PreventDuplicatesStepTrait;

    private $additionalColumns = null;
    private $schemaName = null;
    private $mapperUxon = null;
    private $skipInvalidRows = false;

    /**
     *
     * {@inheritDoc}
     * @throws JSONPathException|\Throwable
     * @see \axenox\ETL\Interfaces\ETLStepInterface::run()
     */
    public function run(ETLStepDataInterface $stepData) : \Generator
    {
        $flowRunUid = $stepData->getFlowRunUid();
    	$stepRunUid = $stepData->getStepRunUid();
    	$placeholders = $this->getPlaceholders($stepData);
    	$result = new UxonEtlStepResult($stepRunUid);
        $task = $stepData->getTask();

        if (! ($task instanceof HttpTaskInterface)){
            throw new InvalidArgumentException('Http request needed to process OpenApi definitions! `' . get_class($task) . '` received instead.');
        }
        
        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeETLStepRun($this));

        $requestLogData = $this->loadRequestData($stepData, ['http_body', 'http_content_type'])->getRow(0);
        $requestBody = $requestLogData['http_body'];

        if ($requestLogData['http_content_type'] !== 'application/json' || $requestBody === null) {
            yield 'No HTTP content found to process' . PHP_EOL;
            return $result->setProcessedRowsCounter(0);
        }

        $toObject = $this->getToObject();
        $apiSchema = $this->getAPISchema($stepData);
        $toObjectSchema = $apiSchema->getObjectSchema($toObject, $this->getSchemaName());

        if ($this->isUpdateIfMatchingAttributes()) {
            $this->addDuplicatePreventingBehavior($this->getToObject());
        } elseif($toObjectSchema->isUpdateIfMatchingAttributes()) {
            $this->addDuplicatePreventingBehavior($toObject, $toObjectSchema->getUpdateIfMatchingAttributeAliases());
        }
        
        $routeSchema = $apiSchema->getRouteForRequest($task->getHttpRequest());
        $requestData = $routeSchema->parseData($requestBody, $toObject);
        $fromSheet = $this->readJson($requestData, $toObjectSchema);

        $this->getCrudCounter()->start([$fromSheet->getMetaObject()]);

        // Perform 'from_data_checks'.
        if (null !== $checksUxon = $this->getFromDataChecksUxon()) {
            $this->performDataChecks($fromSheet, $checksUxon, $flowRunUid, $stepRunUid);
            
            if($fromSheet->countRows() === 0) {
                $this->getCrudCounter()->stop();
                yield 'All input rows removed by failed data checks.' . PHP_EOL;
                return $result->setProcessedRowsCounter(0);
            }
        }
        
        $mapper = $this->getPropertiesToDataSheetMapper($fromSheet->getMetaObject(), $toObjectSchema);
        $toSheet = $mapper->map($fromSheet, false);
        $toSheet = $this->mergeBaseSheet($toSheet, $placeholders);

        // Saving relations is very complex and not yet supported for OpenApi Imports
        // TODO remove this?
        // $toSheet = $this->removeRelationColumns($toSheet);

        yield 'Importing rows ' . $toSheet->countRows() . ' for ' . $toSheet->getMetaObject()->getAlias(). ' with the data sent via webservice request.';

        $writer = $this->saveData(
            $toSheet, 
            $this->getCrudCounter(), 
            $stepData,
            $flowRunUid,
            $stepRunUid, 
            $this->isSkipInvalidRows());

        $this->getCrudCounter()->stop();
        
        yield from $writer;
        $toSheet = $writer->getReturn();
        
        return $result->setProcessedRowsCounter($toSheet->countRows());
    }

    /**
     * @param DataSheetInterface   $toSheet
     * @param CrudCounter          $crudCounter
     * @param ETLStepDataInterface $stepData
     * @param string               $flowRunUid
     * @param string               $stepRunUid
     * @param bool                 $rowByRow
     * @return \Generator
     * @throws \Throwable
     */
    protected function saveData(
        DataSheetInterface $toSheet,
        CrudCounter        $crudCounter, 
        ETLStepDataInterface $stepData, 
        string $flowRunUid,
        string $stepRunUid,
        bool $rowByRow = false) : \Generator
    {
        $crudCounter->addObject($toSheet->getMetaObject());
        if ($rowByRow === true) {
            foreach ($toSheet->getRows() as $i => $row) {
                $saveSheet = $toSheet->copy();
                $saveSheet->removeRows();
                $saveSheet->addRow($row, false, false);
                try {
                    $writer = $this->saveData($saveSheet, $crudCounter, $stepData, $flowRunUid, $stepRunUid, false);
                    foreach ($writer as $line) {
                        // Do nothing, just call the writer
                    }
                } catch (\Throwable $e) {
                    yield 'Error on row ' . $i+1 . '. ' . $e->getMessage() . PHP_EOL;
                    $note = new StepNote(
                        $this->getWorkbench(),
                        $stepData->getFlowRunUid(),
                        $stepData->getStepRunUid(),
                        $e
                    );
                    $note->setMessage('Error on row ' . $i+1 . '. ' . $e->getMessage());
                    $note->takeNote();
                    $this->getWorkbench()->getLogger()->logException($e, LoggerInterface::ERROR);
                }
                //$saveSheet->removeRows();
            }
            return $toSheet;
        }

        $transaction = $this->getWorkbench()->data()->startTransaction();

        try {
            // Perform 'to_data_checks'.
            if (null !== $checksUxon = $this->getToDataChecksUxon()) {
                $this->performDataChecks($toSheet, $checksUxon, $flowRunUid, $stepRunUid);
            }
            
            if($toSheet->countRows() > 0) {
                // we only create new data in import, either there is an import table or a PreventDuplicatesBehavior
                // that can be used to update known entire
                $toSheet->dataCreate(false, $transaction);
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        $transaction->commit();

        return $toSheet;
    }

    protected function mergeBaseSheet(DataSheetInterface $mappedSheet, array $placeholders) : DataSheetInterface
    {
        $baseSheet = $this->createBaseDataSheet($placeholders);
        
        foreach ($baseSheet->getColumns() as $baseCol) {
            if (! $mappedSheet->getColumns()->getByExpression($baseCol->getExpressionObj())) {
                $mappedSheet->getColumns()->add($baseCol);
            }
        }
        return $mappedSheet;
    }

    /**
     * 
     * @param \exface\Core\Interfaces\Model\MetaObjectInterface $fromObj
     * @param \axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface $toObjectSchema
     * @return DataSheetMapperInterface
     */
    protected function getPropertiesToDataSheetMapper(MetaObjectInterface $fromObj, APIObjectSchemaInterface $toObjectSchema) : DataSheetMapperInterface
    {
        $col2col = [];
        $lookups = [];
        foreach ($toObjectSchema->getProperties() as $propName => $propSchema) {
            switch (true) {
                // If a x-lookup is used, transform into a lookup mapping.
                case null !== $lookup = $propSchema->getLookupUxon():
                    $attr = $propSchema->getAttribute();
                    switch (true) {
                        // If the lookup has a `to` property, we already know, in which column we
                        // need to place the value. If we have an x-attribute-alias too, we will
                        // have two columns in the end.
                        case $lookup->hasProperty('to') === true:
                            $lookups[] = $lookup;
                            $lookupToSeparateColumn = $attr !== null;
                            break;
                        // If there is no `to` property, we will use the attribute alias as the to-column.
                        case $attr !== null && $lookup->hasProperty('to') === false:
                            $lookup->setProperty('to', $attr->getAliasWithRelationPath());
                            $lookups[] = $lookup;
                            $lookupToSeparateColumn = false;
                            break;
                        default:
                            throw new RuntimeException('Cannot use x-lookup in OpenAPI if neither `x-attribute-alias` for the property, nor `to` for the x-lookup are  defined');
                    }
                    if ($lookupToSeparateColumn === false) {
                        break;
                    }
                // If the property is to be put into an attribute, create a column-to-column mapping. The
                // from-expression is either the property itself or calculate it using a formula if x-calculation 
                // is defined.
                case $propSchema->isBoundToAttribute() && null !== $attr = $propSchema->getAttribute():
                    if ($propSchema->isBoundToCalculation()) {
                        $from = '=' . ltrim($propSchema->getCalculationExpression()->__toString(), '=');
                    } else {
                        $from = $propName;
                    }
                    $col2col[] = [
                        'from' => $from,
                        'to' => $attr->getAlias(),
                        'ignore_if_missing_from_column' => ! $propSchema->isRequired()
                    ];
                    break;
            }
        }
        $uxon = new UxonObject([
            'from_object_alias' => $fromObj->getAliasWithNamespace(),
            'to_object_alias' => $toObjectSchema->getMetaObject()->getAliasWithNamespace()
        ]);
        if (null !== $customMapperUxon = $this->getPropertiesToDataMapperUxon()) {
            $uxon = $customMapperUxon->extend($uxon);
        }
        if (! empty($col2col)) {
            $uxon->setProperty('column_to_column_mappings', new UxonObject($col2col));
        }
        if (! empty($lookups)) {
            $uxon->setProperty('lookup_mappings', new UxonObject($lookups));
        }
        // TODO Add DataColumnToJsonMapping's here
        return DataSheetMapperFactory::createFromUxon($this->getWorkbench(), $uxon);
    }

    /**
     * Custom mapper to map properties of the API schema to the data sheet.
     * 
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheetMapper
     * @uxon-property properties_to_data_sheet_mapper
     * @uxon-template {"column_to_column_mappings": [{"from": "", "to": ""}]}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return JsonApiToDataSheet
     */
    protected function setPropertiesToDataSheetMapper(UxonObject $uxon) : JsonApiToDataSheet 
    {
        $this->mapperUxon = $uxon;
        return $this;
    }

    protected function setMapper(UxonObject $uxon) : JsonApiToDataSheet 
    {
        return $this->setPropertiesToDataSheetMapper($uxon);
    }

    /**
     * 
     * @return UxonObject
     */
    protected function getPropertiesToDataMapperUxon() : ?UxonObject
    {
        return $this->mapperUxon;
    }

    /**
     * 
     * @param array $requestBody
     * @param array $toObjectSchema
     * @param string|null $key
     * @param string $objectAlias
     * @return DataSheetInterface
     */
    protected function readJson(array $data, APIObjectSchemaInterface $toObjectSchema) : DataSheetInterface
    {
        $dataSheet = DataSheetFactory::createFromObject($this->createFakeObject($toObjectSchema));

        if (ArrayDataType::isSequential($data)) {
            // Named array: { "object-key" [ {"id": "123", "name": "abc" }, {"id": "234", "name": "cde"} ] }
            // Unnamed array: [ {"id": "123", "name": "abc" }, {"id": "234", "name": "cde"} ]
            foreach ($data as $entry) {
                $row = $this->readJsonRow($entry, $toObjectSchema);
                $dataSheet->addRow($row);
            }
        } else {
            // Named object: { "object-key" {"id": "123", "name": "abc" } }
            // Unnamed object: {"id": "123", "name": "abc" }
            $row = $this->readJsonRow($data, $toObjectSchema);
            $dataSheet->addRow($row);
        }
        
        return $dataSheet;
    }

    /**
     * Generate a temporary dummy object that serves as basis for the from-sheet. 
     * Its attributes are derived from the API schema.
     * 
     * @param APIObjectSchemaInterface $schema
     * @return MetaObjectInterface
     */
    protected function createFakeObject(APIObjectSchemaInterface $schema) : MetaObjectInterface
    {
        $result = MetaObjectFactory::createTemporary(
            $this->getWorkbench(),
            'JsonApiToDataSheet.Dummy',
            '',
            '',
            'exface.Core.METAMODEL_DB'
        );

        foreach ($schema->getProperties() as $propSchema) {
            $attrAlias = $propSchema->getPropertyName();
            MetaObjectFactory::addAttributeTemporary(
                $result,
                $attrAlias,
                $attrAlias,
                '',
                $propSchema->guessDataType()
            );
        }
        
        return $result;
    }

    /**
     * 
     * @param array $bodyLine
     * @param string[] $neededProperties
     * @return string[]
     */
    protected function readJsonRow(array $bodyLine, APIObjectSchemaInterface $toObjectSchema) : array
    {
        $importData = [];
        foreach ($bodyLine as $propertyName => $value) {
            $property = $toObjectSchema->getProperty($propertyName);
            switch(true) {
                // If we are not looking for this property, but it is a real array, see if it contains
                // objects with properties we are looking for.
                // IDEA 15.04.2025: not sure, why we do this - perhaps, it is not needed anymore
                case $property === null && is_numeric($propertyName):
                    $importData = array_merge($importData, $this->readJsonRow($value, $toObjectSchema));
                    break;
                // Otherwise skip properties, that we are not looking for explicitly
                case $property === null:
                    break;
                // Array values, that are to be placed in a DataSheet need to be converted to a
                // delimited list
                case $property->getPropertyType() === 'array' && $property->isBoundToMetamodel():
                    if (is_array($value)) {
                        if ($property->isBoundToAttribute()) {
                            $value = implode($property->getAttribute()->getValueListDelimiter(), $value);
                        } else {
                            $value = implode(EXF_LIST_SEPARATOR, $value);
                        }
                    }
                    $importData[$propertyName] = $value;
                    break;
                // Object values, that are to be put in a DataSheet also need to be converted to string,
                // but they are converted to JSON - e.g. geometries for geo data
                case $property->getPropertyType() === 'object' && $property->isBoundToMetamodel():
                    if (is_array($value)) {
                        $value =  json_encode($value);
                    }
                    $importData[$propertyName] = $value;
                    break;
                // Take regular properties as-is
                default:
                    $importData[$propertyName] = $value;
                    break;
            }
        }

        return $importData;
    }

    /**
     * Define the name of the schema for this specific step.
     * If null, it will try to find the attribute alias within the OpenApi definition.
     *
     * @uxon-property schema_name
     * @uxon-type string
     *
     * @param string $schemaName
     * @return JsonApiToDataSheet
     */
    protected function setSchemaName(string $schemaName) : JsonApiToDataSheet
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
        return new UxonEtlStepResult($stepRunUid, $resultData);
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

    /**
     * Set to TRUE to import rows one-by-one and skip rows causing errors.
     * 
     * By default, the step will process all rows at once and will not write anything if
     * at least one error happens.
     * 
     * @uxon-property skip_invalid_rows
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $trueOrFalse
     * @return JsonApiToDataSheet
     */
    protected function setSkipInvalidRows(bool $trueOrFalse) : JsonApiToDataSheet
    {
        $this->skipInvalidRows = $trueOrFalse;
        return $this;
    }

    protected function isSkipInvalidRows() : bool
    {
        return $this->skipInvalidRows;
    }
}