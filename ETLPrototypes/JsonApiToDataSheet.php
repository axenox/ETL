<?php
namespace axenox\ETL\ETLPrototypes;

use axenox\ETL\Common\AbstractAPISchemaPrototype;
use axenox\ETL\Common\NoteTaker;
use axenox\ETL\Common\StepNote;
use axenox\ETL\Common\Traits\PreventDuplicatesStepTrait;
use axenox\ETL\Events\Flow\OnAfterETLStepRun;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\CommonLogic\Debugger\LogBooks\FlowStepLogBook;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\Exceptions\DataSheets\DataSheetMissingRequiredValueError;
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
use exface\Core\Interfaces\Log\LoggerInterface;

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

        if (! ($task instanceof HttpTaskInterface)){
            throw new InvalidArgumentException('Http request needed to process OpenApi definitions! `' . get_class($task) . '` received instead.');
        }
        
        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeETLStepRun($this, $logBook));

        $requestLogData = $this->loadRequestData($stepData, ['http_body', 'http_content_type'])->getRow(0);
        $requestBody = $requestLogData['http_body'];

        if ($requestLogData['http_content_type'] !== 'application/json' || $requestBody === null) {
            $logBook->addLine($msg = 'No HTTP content found to process');
            
            $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
            
            yield $msg . PHP_EOL;
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
        $logBook->addDataSheet('JSON data', $fromSheet);
        
        $logBook->addLine('Extracted JSON data to `From-Sheet`.');
        $logBook->addDataSheet('From-Sheet', $fromSheet->copy());
        
        $this->getCrudCounter()->start([$fromSheet->getMetaObject()]);

        // Perform 'from_data_checks'.
        if (null !== $checksUxon = $this->getFromDataChecksUxon()) {
            $this->performDataChecks($fromSheet, $checksUxon, 'Data Checks: From-Sheet', $stepData, $logBook);
            
            if($fromSheet->countRows() === 0) {
                $this->getCrudCounter()->stop();
                $logBook->addLine($msg = 'All input rows removed by failed data checks.');

                $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
                
                yield $msg . PHP_EOL;
                return $result->setProcessedRowsCounter(0);
            }
        }
        
        $logBook->addSection('Filling data sheet');
        $mapper = $this->getPropertiesToDataSheetMapper($fromSheet->getMetaObject(), $toObjectSchema);

        try {
            $toSheet = $mapper->map($fromSheet, false, $logBook);
        } catch (\Throwable $error)
        {
            $this->handleMapperException($error, $fromSheet, $stepData);
        }
        
        $toSheet = $this->mergeBaseSheet($toSheet, $placeholders);

        $logBook->addLine('Mapped `From-Sheet` according to schema "' . get_class($toObjectSchema) . '" resulting in `To-Sheet`.');
        $logBook->addDataSheet('To-data', $toSheet);
        
        // Saving relations is very complex and not yet supported for OpenApi Imports
        // TODO remove this?
        // $toSheet = $this->removeRelationColumns($toSheet);
        
        $msg = 'Importing **' . $toSheet->countRows() . '** rows for ' . $toSheet->getMetaObject()->getAlias(). ' from JSON web service request.';
        $logBook->addLine($msg);
        yield $msg;
        
        $writer = $this->writeData(
            $toSheet, 
            $this->getCrudCounter(), 
            $stepData,
            $logBook,
            $this->isSkipInvalidRows()
        );
        
        $logBook->addSection('Saving data');
        yield from $writer;
        $resultSheet = $writer->getReturn();
        $logBook->addLine('Saved **' . $resultSheet->countRows() . '** rows of "' . $resultSheet->getMetaObject()->getAlias(). '".');
        if ($toSheet !== $resultSheet) {
            $logBook->addDataSheet('To-data as saved', $resultSheet);
        }

        $this->getCrudCounter()->stop();

        $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
        
        return $result->setProcessedRowsCounter($resultSheet->countRows());
    }

    /**
     * @param \Throwable           $exception
     * @param DataSheetInterface   $fromSheet
     * @param ETLStepDataInterface $stepData
     * @return void
     * @throws \Throwable
     */
    protected function handleMapperException(\Throwable $exception, DataSheetInterface $fromSheet, ETLStepDataInterface $stepData) : void
    {
        $e = $exception->getPrevious();

        if($e instanceof DataSheetMissingRequiredValueError) {
            $rowToken = count($e->getRowNumbers()) === 1 ? 'ROW.SINGULAR' : 'ROW.PLURAL';
            $rowToken = $this->getWorkbench()->getCoreApp()->getTranslator()->translate($rowToken);
            $rowNrs = $e->getRowNumbers();
            foreach ($rowNrs as $i => $rowNr) {
                $rowNrs[$i] += 1;
            }

            $message = 'Invalid or missing data in column `' . $e->getColumnName() . '` for ' . $rowToken . ' (' . implode(',', $rowNrs) . ')';
            $e = new DataSheetMissingRequiredValueError(
                $fromSheet,
                $message,
                $e->getAlias(),
                $e,
                $e->getColumn(),
                $rowNrs
            );
            $e->setUseExceptionMessageAsTitle(true);

            NoteTaker::takeNote(new StepNote(
                $this->getWorkbench(),
                $stepData,
                $message,
                $e,
                $e->getLogLevel()
            ));


            throw $e;
        }

        throw $exception;
    }

    /**
     * @param DataSheetInterface   $toSheet
     * @param CrudCounter          $crudCounter
     * @param ETLStepDataInterface $stepData
     * @param FlowStepLogBook      $logBook
     * @param bool                 $rowByRow
     * @return \Generator
     * @throws \Throwable
     */
    protected function writeData(
        DataSheetInterface $toSheet,
        CrudCounter        $crudCounter, 
        ETLStepDataInterface $stepData, 
        FlowStepLogBook $logBook,
        bool $rowByRow = false) : \Generator
    {
        $crudCounter->addObject($toSheet->getMetaObject());
        $resultSheet = null;

        if ($rowByRow === true) {
            $saveSheet = $toSheet;
            $resultSheet = null;
            foreach ($toSheet->getRows() as $i => $row) {
                $saveSheet = $saveSheet->copy();
                $saveSheet->removeRows();
                $saveSheet->addRow($row, false, false);
                if ($i > 0) {
                    $logBook->addSection('Saving row index ' . $i);
                }
                try {
                    $writer = $this->writeData(
                        $saveSheet, 
                        $crudCounter, 
                        $stepData, 
                        $logBook,
                        false
                    );
                    // Write the line
                    foreach ($writer as $line) {
                        // Do nothing, just call the writer
                    }
                    // Get the resulting data sheet of that single line an add it to the global
                    // result data
                    $rowResultSheet = $writer->getReturn();
                    if ($resultSheet === null) {
                        $resultSheet = $rowResultSheet;
                    } else {
                        foreach ($rowResultSheet->getRows() as $resultRow) {
                            $resultSheet->addRow($resultRow, false, false);
                        }
                    }
                } catch (\Throwable $e) {
                    // If anything goes wrong, just continue with the next row
                    yield 'Error on row ' . $i+1 . '. ' . $e->getMessage() . PHP_EOL;
                    NoteTaker::takeNote(new StepNote(
                        $this->getWorkbench(),
                        $stepData,
                        'Error on row ' . $i+1 . '.',
                        $e
                    ));
                    
                    $this->getWorkbench()->getLogger()->logException($e, LoggerInterface::ERROR);
                }
            }
            
        } else {

            foreach($this->getOutputMappers() as $i => $mapper) {
                $toSheet = $mapper->map($toSheet, false, $logBook);
            }
            if ($toSheet->isEmpty()) {
                return $toSheet;
            }

            // Perform 'to_data_checks' only in regular mode. Per-row-mode (see above) will perform regular
            // writes for each row, so it will end up here anyway
            if (null !== $checksUxon = $this->getToDataChecksUxon()) {
                $this->performDataChecks($toSheet, $checksUxon, 'Data Checks: To-Sheet', $stepData, $logBook);

                if($toSheet->countRows() === 0) {
                    $logBook->addLine('All input rows removed by failed data checks.');
                    return $toSheet;
                }
            }

            $transaction = $this->getWorkbench()->data()->startTransaction();

            try {
                // we only create new data in import, either there is an import table or a PreventDuplicatesBehavior
                // that can be used to update known entire
                $toSheet->dataCreate(false, $transaction);
            } catch (\Throwable $e) {
                throw $e;
            }

            $transaction->commit();

            $resultSheet = $toSheet;
        }

        // If no row actually worked, we will not have a result sheet at all. This means, nothing was
        // written. However, it is easier to understand, what happened if we return an empty sheet
        // and not NULL, so we just compy the to-sheet and empty it.
        if ($resultSheet === null) {
            $resultSheet = $toSheet->copy()->removeRows();
        }

        return $resultSheet;
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
     * @return UxonObject
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