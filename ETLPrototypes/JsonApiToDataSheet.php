<?php
namespace axenox\ETL\ETLPrototypes;

use axenox\ETL\Common\AbstractAPISchemaPrototype;
use axenox\ETL\Common\StepNote;
use axenox\ETL\Common\Traits\PreventDuplicatesStepTrait;
use axenox\ETL\Events\Flow\OnAfterETLStepRun;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\NoteInterface;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\CommonLogic\DataSheets\Mappings\DataColumnMapping;
use exface\Core\CommonLogic\Debugger\LogBooks\FlowStepLogBook;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\MessageTypeDataType;
use exface\Core\Exceptions\DataSheets\DataSheetInvalidValueError;
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
use exface\Core\CommonLogic\DataSheets\DataColumn;

/**
 * Imports data received through an annotated API (like OpenAPI) using object and attribute annotations.
 * 
 * This steps allows to transform JSON web service requests into data sheets without having to describe
 * the structure of the JSON as an object of the meta model. Instead, you can map JSON properties directly
 * to attributes of any meta object of you choice directly in the web service definition using simple
 * annotations.
 * 
 * This step can be easyily combined with `ExcelApiToDataSheet` to use the same API definition to import
 * JSON data as well as uploaded Excel data. In this case, special `x-excel-` annotations will become
 * available too.
 * 
 * For example, in an OpenAPI3 web service, we can define a schema to import orders directly into the
 * `ORDER` object of our app. Using the `x-object-alias` annotation we bind the JSON schema to the meta
 * object. Now we can map properties of the JSON order object to attributes via `x-attribute-alias`.
 * 
 * ```
 * {
 *  "schemas": {
 *      "Order": {
 *          "type": "object",
 *          "x-object-alias": "my.App.ORDER",
 *          "properties: {
 *              "Number" {
 *                  "type": "string",
 *                  "description": "Order number",
 *                  "x-attribute-alias": "NUMBER"
 *              },
 *              "Date" {
 *                  "type": "string",
 *                  "description": "Order date formatted as DD.MM.YYYY",
 *                  "example": "23.06.2024",
 *                  "x-calculation": "=Date(Date)",
 *                  "x-attribute-alias": "DATE"
 *              },
 *              "SupplierNo" {
 *                  "type": "string",
 *                  "description": "Supplier number",
 *                  "x-attribute-alias": "SUPPLIER",
 *                  "x-lookup": {
 *                      "lookup_object_alias": "my.App.SUPPLIER",
 *                      "lookup_column": "UID",
 *                      "matches": [
 *                          {"from": "SupplierNo", "lookup": "NO"}
 *                      ]
 *                  }
 *              }
 *          }
 *      }
 *  }
 * }
 * 
 * ```
 * 
 * As you can see, each JSON property will be transformed into an attribute of the `ORDER`:
 * 
 * - `Number` will be copied to the `NUMBER` attribute as-is
 * - `Date` is expected in DD.MM.YYYY in JSON, so it will be parsed using the `=Date()` formula before
 * being passed to the `DATE` attribute
 * - `SupplierNo` is the supplier number, while we need the UID of the `SUPPLIER` object in our `SUPPLIER`
 * attribute - that is why we need an `x-lookup` to search for the `SupplierNo` in the `NO` column of our
 * supplier data and lookup the UID of the matching row.
 * 
 * Most of the schema is regular JSON schema syntax. Merely the `x-` annotatios are added by this step
 * specifically. All in all, it still remains a valid OpenAPI3 definition and will be correctly parsed
 * by any OpenAPI library.
 * 
 * ## Workflow
 * 
 * Technically, the step will
 * 
 * 1. Read the web service request into a data sheet based on an auto-created temporary meta object. It
 * will have attributes/columns for every JSON property.
 * 2. Run the `from_data_checks` on that temporary data sheet if defined in the step
 * 3. Transform this data sheet to one based on the to-object of the step by mapping JSON-property columns
 * to the respective `x-attribute-alias`. Other annotations like `x-calculation` or `x-lookup` allow to
 * further customize the mappings applied.
 * 4. Save the entire data sheet or every row separately depending on `skip_invalid_rows`. Thus, subsequent
 * operations will either be applied to every single row or the the entire data sheet as a whole.
 * 5. Run `to_data_checks` on the resulting data sheet if defined in the step
 * 6. Apply additional `output_mappers` allowing to further transform the resulting data - e.g. transpose
 * columns, etc.
 * 
 * ## Annotations
 * 
 * ### OpenAPI v3
 * 
 * A schema used in OpenAPI can be bound to a meta object suing `x-` annotations in that schema.
 * 
 * #### Schema annotations
 * 
 * - `x-object-alias` - namespaced alias of the object represented by this schema
 * - `x-update-if-matching-attributes` - array of attribute aliases to use to determine if the import row
 * is a create or an update.
 * 
 * #### Property annotations
 * 
 * Each schema property the following cusotm OpenAPI properties:
 * 
 * - `x-attribute-alias` - the meta model attribute this property is bound to
 * - `x-lookup` - the Uxon object to look up for this property
 * - `x-calculation` - the calculation expression for this property
 * - `x-custom-attribute` - create a custom attribute for the object right here (for export-steps only)
 * - `x-excel-column` - only if a `ExcelApiToDataSheet` step exists int the same flow
 * 
 * #### Property template annotations
 * 
 * Some special annotations make a schma property be treated as a template. In this case, it will be
 * replaced with multiple auto-generated properties when the OpenAPI.json is rendered.
 * 
 * - `x-attribute-group-alias` - replaces this property with properties generated from each attribute of
 * the group. If the property has any other options like `type`, `description`, etc. they will be used
 * as a template and remain visible unless the generated properties overwrite them
 * - `x-properties-from-data` replace this property with properties generated from a data sheet. In contrast
 * to `x-attribute-group-alias`, this properties may not even be bound to meta attributes - they can be
 * generated absolutely freely. Other options of the property may use placeholders, that will be filled with
 * values from the data sheet.
 *
 * ## Customizing the resulting data sheet
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
 * ## Applying additional logic via output mappers
 * 
 * TODO
 *
 * @author Andrej Kabachnik, Georg Bieger
 */
class JsonApiToDataSheet extends AbstractAPISchemaPrototype
{
    use PreventDuplicatesStepTrait;

    private $additionalColumns = null;
    private $schemaName = null;
    private $propertiesMapperUxon = null;
    private $outputMapperUxon = null;
    private $skipInvalidRows = false;
    
    /**
     *
     * {@inheritDoc}
     * @throws JSONPathException|\Throwable
     * @see \axenox\ETL\Interfaces\ETLStepInterface::run()
     */
    public function run(ETLStepDataInterface $stepData) : \Generator
    {
        $placeholders = $this->getPlaceholders($stepData);
    	$result = new UxonEtlStepResult($stepData->getStepRunUid());
        $task = $stepData->getTask();
        $logBook = $this->getLogBook($stepData);
        $this->getCrudCounter()->reset();

        if (! ($task instanceof HttpTaskInterface)){
            throw new InvalidArgumentException('Http request needed to process OpenApi definitions! `' . get_class($task) . '` received instead.');
        }
        
        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeETLStepRun($this, $logBook));

        $requestLogData = $this->loadRequestData($stepData, ['http_body', 'http_content_type'])->getRow(0);
        $requestBody = $requestLogData['http_body'];

        if ($requestLogData['http_content_type'] !== 'application/json' || $requestBody === null) {
            $logBook->addLine($msg = 'No HTTP content found to process.');
            yield $msg . PHP_EOL;
            
            $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
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

        $logBook->addSection('Reading from-sheet');
        $routeSchema = $apiSchema->getRouteForRequest($task->getHttpRequest());
        $requestData = $routeSchema->parseData($requestBody, $toObject);
        $fromSheet = $this->readJson($requestData, $toObjectSchema);
        $logBook->addLine("Read {$fromSheet->countRows()} rows from JSON into from-sheet based on {$fromSheet->getMetaObject()->__toString()}");
        $logBook->addDataSheet('JSON data', $fromSheet->copy());
        $this->getCrudCounter()->addValueToCounter($fromSheet->countRows(), CrudCounter::COUNT_READS);

        $this->startTrackingData(
            $this->getUidColumnsFromSchema($toObjectSchema, $fromSheet),
            $stepData,
            $logBook
        );
        
        // Perform 'from_data_checks'.
        $this->performDataChecks($fromSheet, $this->getFromDataChecksUxon(), 'from_data_checks', $stepData, $logBook);
        $logBook->addDataSheet('From-Sheet', $fromSheet);
        
        $logBook->addSection('Filling to-sheet');
        $mapper = $this->getPropertiesToDataSheetMapper($fromSheet->getMetaObject(), $toObjectSchema);
        $toSheet = $this->applyDataSheetMapper($mapper, $fromSheet, $stepData, $logBook, $this->skipInvalidRows);
        
        if($toSheet->countRows() === 0) {
            $logBook->addLine($msg = 'All input rows removed because of invalid or missing data. **Exiting step**.');

            $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
            throw new RuntimeException('All input rows failed to write or were skipped due to errors!', '81VV7ZF');
        }
        
        $toSheet = $this->mergeBaseSheet($toSheet, $placeholders, $stepData);
        $logBook->addDataSheet('To-Sheet', $toSheet);
        
        // Saving relations is very complex and not yet supported for OpenApi Imports
        // TODO remove this?
        // $toSheet = $this->removeRelationColumns($toSheet);

        $logBook->addSection('Saving data');
        $msg = 'Importing **' . $toSheet->countRows() . '** rows for ' . $toSheet->getMetaObject()->getAlias(). ' from JSON web service request.';
        $logBook->addLine($msg);
        yield $msg;

        $this->getCrudCounter()->start([], false, [CrudCounter::COUNT_READS]);
        
        $resultSheet = $this->writeData(
            $toSheet, 
            $this->getCrudCounter(), 
            $stepData,
            $logBook
        );
        
        $logBook->addLine('Saved **' . $resultSheet->countRows() . '** rows of "' . $resultSheet->getMetaObject()->getAlias(). '".');
        if ($toSheet !== $resultSheet) {
            $logBook->addDataSheet('To-data as saved', $resultSheet);
        }

        $this->getCrudCounter()->stop();

        $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
        
        return $result->setProcessedRowsCounter($resultSheet->countRows());
    }

    /**
     * Returns all columns from the `x-object-uid` property in the schema.
     * 
     * @param APIObjectSchemaInterface $schema
     * @param DataSheetInterface       $dataSheet
     * @return array
     */
    protected function getUidColumnsFromSchema(APIObjectSchemaInterface $schema, DataSheetInterface $dataSheet) : array
    {
        $columns = [];
        $columnsFromData = $dataSheet->getColumns();

        foreach ($schema->getUidProperties() as $prop) {
            if($columnsFromData->has($prop)) {
                $columns[] = $columnsFromData->get($prop);
            }
        }
        
        return $columns;
    }

    /**
     * @param DataSheetMapperInterface $mapper
     * @param DataSheetInterface       $fromSheet
     * @param ETLStepDataInterface     $stepData
     * @param FlowStepLogBook          $logBook
     * @param bool                     $rowByRow
     * @return DataSheetInterface
     * @throws \Throwable
     */
    protected function applyDataSheetMapper(
        DataSheetMapperInterface $mapper,
        DataSheetInterface $fromSheet,
        ETLStepDataInterface $stepData,
        FlowStepLogBook $logBook,
        bool $rowByRow
    ) : DataSheetInterface
    {
        // Setup for data tracking.
        $fromTrackedAliases = $this->getTrackedAliases();
        $toTrackedAliases = [];
        
        foreach ($mapper->getMappings() as $mapping) {
            // TODO Are there other mappings, where one column might transform into another?
            if(!$mapping instanceof  DataColumnMapping) {
                continue;
            }
            
            $fromAlias = $mapping->getFromExpression()->getAttributeAlias();
            if(key_exists($fromAlias, $fromTrackedAliases)) {
                $toAlias = $mapping->getToExpression()->getAttributeAlias();
                $toTrackedAliases[$fromAlias] = $toAlias;
            }
        }

        $toTrackedAliases = array_merge($fromTrackedAliases, $toTrackedAliases);

        if($rowByRow) {
            $passLogBook = true;
            $toSheet = $this->applyTransformRowByRow(
                function ($i, $data) use (
                    $mapper, 
                    $logBook, 
                    &$passLogBook, 
                    $fromTrackedAliases,
                    $toTrackedAliases
                ) {
                    
                    if($passLogBook) {
                        $passLogBook = false;
                        $result = $mapper->map($data, false, $logBook);
                    } else {
                        $result = $mapper->map($data, false);
                    }
                    
                    $this->getDataTracker()?->recordDataTransform(
                        $data->getColumns()->getMultiple($fromTrackedAliases),
                        $result->getColumns()->getMultiple($toTrackedAliases)
                    );
                    
                    return $result;
                },
                $fromSheet,
                $stepData,
                $logBook,
                NoteInterface::VISIBLE_FOR_EVERYONE
            );
        } else {
            $translator = $this->getTranslator();
            
            try {
                // FIXME recalculate row numbers here as soon as row-bound exceptions support it.
                $toSheet = $mapper->map($fromSheet, false, $logBook);
                $this->getDataTracker()?->recordDataTransform(
                    $fromSheet->getColumns()->getMultiple($fromTrackedAliases),
                    $toSheet->getColumns()->getMultiple($toTrackedAliases)
                );
            } catch (\Throwable $exception) {
                $failedToFind = [];
                $baseData = [];
                
                $prev = $exception->getPrevious();
                if($prev instanceof DataSheetInvalidValueError) {
                    $errorSheet = $fromSheet->copy()->removeRows()->addRows($prev->getRowIndexes());
                    $baseData = $this->getDataTracker()?->getBaseDataForSheet(
                        $errorSheet, 
                        $failedToFind,
                        [$this, 'toDisplayRowNumber']
                    );
                }

                StepNote::fromException(
                    $this->getWorkbench(),
                    $stepData,
                    $exception
                )->enrichWithAffectedData(
                    $baseData,
                    $failedToFind,
                    $translator
                )->takeNote();
                
                throw $exception;
            }
        }

        return $toSheet;
    }

    /**
     * @param DataSheetInterface   $toSheet
     * @param CrudCounter          $crudCounter
     * @param ETLStepDataInterface $stepData
     * @param FlowStepLogBook      $logBook
     * @return DataSheetInterface
     * @throws \Throwable
     */
    protected function writeData(
        DataSheetInterface $toSheet,
        CrudCounter        $crudCounter, 
        ETLStepDataInterface $stepData, 
        FlowStepLogBook $logBook) : DataSheetInterface
    {
        $crudCounter->addObject($toSheet->getMetaObject());

        if ($this->skipInvalidRows) {
            // Apply output mappers.
            foreach ($this->getOutputMappers() as $mapper) {
                $toSheet = $this->applyDataSheetMapper($mapper, $toSheet, $stepData, $logBook,true);
            }

            // Perform 'to_data_checks' only in regular mode. Per-row-mode (see above) will perform regular
            // writes for each row, so it will end up here anyway.
            if (null !== $checksUxon = $this->getToDataChecksUxon()) {
                $this->performDataChecks($toSheet, $checksUxon, 'to_data_checks', $stepData, $logBook);
            }
            
            // Perform write.
            $resultSheet = $this->applyTransformRowByRow(
                function ($i, $data) {
                    return $this->performWrite($data);
                },
                $toSheet,
                $stepData,
                $logBook,
                NoteInterface::VISIBLE_FOR_SUPERUSER
            );
            
        } else {
            foreach($this->getOutputMappers() as $mapper) {
                $toSheet = $this->applyDataSheetMapper($mapper, $toSheet, $stepData, $logBook, false);
            }

            // Perform 'to_data_checks' only in regular mode. Per-row-mode (see above) will perform regular
            // writes for each row, so it will end up here anyway
            if (null !== $checksUxon = $this->getToDataChecksUxon()) {
                $this->performDataChecks($toSheet, $checksUxon, 'to_data_checks', $stepData, $logBook);
            }
            
            $resultSheet = $this->performWrite($toSheet);
        }
        // If no row actually worked, nothing was written, and we will report a failed step.
        if ($resultSheet->countRows() === 0) {
            throw new RuntimeException('All input rows failed to write or skipped due to errors!', '81VV7ZF');
        }
        
        return $resultSheet;
    }

    /**
     * @param DataSheetInterface $data
     * @return DataSheetInterface
     */
    protected function performWrite(
        DataSheetInterface   $data,
    ) : DataSheetInterface
    {
        if ($data->isEmpty()) {
            return $data;
        }

        $transaction = $this->getWorkbench()->data()->startTransaction();

        try {
            // we only create new data in import, either there is an import table or a PreventDuplicatesBehavior
            // that can be used to update known entire
            $data->dataCreate(false, $transaction);
        } catch (\Throwable $e) {
            throw $e;
        }

        $transaction->commit();
        return $data;
    }

    /**
     * @param DataSheetInterface   $mappedSheet
     * @param array                $placeholders
     * @param ETLStepDataInterface $stepData
     * @return DataSheetInterface
     */
    protected function mergeBaseSheet(DataSheetInterface $mappedSheet, array $placeholders, ETLStepDataInterface $stepData) : DataSheetInterface
    {
        $baseSheet = $this->createBaseDataSheet($placeholders);
        
        foreach ($baseSheet->getColumns() as $baseCol) {
            if (! $mappedSheet->getColumns()->getByExpression($baseCol->getExpressionObj())) {
                $mappedSheet->getColumns()->add($baseCol);
            }
        }
        foreach ($mappedSheet->getColumns() as $column) {
            if(!$column->isFresh() && $column->isFormula()) {
                try {
                    $column->setValuesByExpression($column->getFormula());
                    $column->setFresh(true);
                } catch (\Throwable $e) {
                    (new StepNote(
                        $this->getWorkbench(),
                        $stepData,
                        'Cannot load column "' . $column->getName() . '" for base sheet! ' . $e->getMessage(),
                        $e,
                        MessageTypeDataType::WARNING
                    ))->takeNote();
                }
            }
        }
        
        return $mappedSheet;
    }

    /**
     * 
     * @param MetaObjectInterface      $fromObj
     * @param APIObjectSchemaInterface $toObjectSchema
     * @return DataSheetMapperInterface
     */
    protected function getPropertiesToDataSheetMapper(
        MetaObjectInterface $fromObj, 
        APIObjectSchemaInterface $toObjectSchema
    ) : DataSheetMapperInterface
    {
        $col2col = [];
        $lookups = [];
        $object = $toObjectSchema->getMetaObject();

        foreach ($toObjectSchema->getProperties() as $propName => $propSchema) {
            switch (true) {
                // If a x-lookup is used, transform into a lookup mapping.
                case null !== $lookup = $propSchema->getLookupUxon():
                    // If this property wasn't set explicitly, we make the lookup optional if its attribute isn't required.
                    if(!$lookup->hasProperty('ignore_if_missing_from_column')) {
                        $lookup->setProperty('ignore_if_missing_from_column', !$propSchema->isRequired());
                    }
                    
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
                case $propSchema->isBoundToData():
                    $col2col[] = [
                        'from' => $propName,
                        'to' => $propName,
                        'ignore_if_missing_from_column' => ! $propSchema->isRequired()
                    ];
                    break;
            }
        }
        $uxon = new UxonObject([
            'from_object_alias' => $fromObj->getAliasWithNamespace(),
            'to_object_alias' => $object->getAliasWithNamespace()
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
        $this->propertiesMapperUxon = $uxon;
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
        return $this->propertiesMapperUxon;
    }

    /**
     * Additional mapper applied before the result data sheet is saved
     * 
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheetMapper[]
     * @uxon-property output_mappers
     * @uxon-template [{"column_to_column_mappings": [{"from": "", "to": ""}]}]
     * 
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return JsonApiToDataSheet
     */
    protected function setOutputMappers(UxonObject $uxon) : JsonApiToDataSheet 
    {
        $this->outputMapperUxon = $uxon;
        return $this;
    }

    /**
     *
     * @return array
     */
    protected function getOutputMappers() : array
    {
        if ($this->outputMapperUxon === null || $this->outputMapperUxon->isEmpty()) {
            return [];
        }
        $mappers = [];
        foreach ($this->outputMapperUxon as $mapperUxon) {
            $mappers[] = DataSheetMapperFactory::createFromUxon($this->getWorkbench(), $mapperUxon, $this->getToObject(), $this->getToObject());
        }
        return $mappers;
    }

    /**
     *
     * @param array                    $data
     * @param APIObjectSchemaInterface $toObjectSchema
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
            $colName = DataColumn::sanitizeColumnName($propertyName);
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
                    $importData[$colName] = $value;
                    break;
                // Object values, that are to be put in a DataSheet also need to be converted to string,
                // but they are converted to JSON - e.g. geometries for geo data
                case $property->getPropertyType() === 'object' && $property->isBoundToMetamodel():
                    if (is_array($value)) {
                        $value =  json_encode($value);
                    }
                    $importData[$colName] = $value;
                    break;
                // Take regular properties as-is
                default:
                    $importData[$colName] = $value;
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
     * NOTE: This setting does not affect data checks! If a row fails a data check that has
     * `stop_on_check_failed = TRUE`, the entire step will be terminated, even if `skip_invalid_rows = TRUE`.
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