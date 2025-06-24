<?php
namespace axenox\ETL\ETLPrototypes;

use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\CommonLogic\Filesystem\DataSourceFileInfo;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\SemanticVersionDataType;
use exface\Core\Exceptions\DataSheets\DataSheetMissingRequiredValueError;
use exface\Core\Exceptions\DataTypes\JsonSchemaValidationError;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;
use exface\Core\Interfaces\Model\Behaviors\FileBehaviorInterface;
use exface\Core\QueryBuilders\ExcelBuilder;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use Flow\JSONPath\JSONPathException;
use axenox\ETL\Common\UxonEtlStepResult;
use axenox\ETL\Events\Flow\OnAfterETLStepRun;

/**
 * Objects have to be defined with an x-object-alias and with x-attribute-aliases for the object to fill
 * AND x-excel-sheet and with x-excel-column for the information where to read the information in the excel
 * like:
 * ´´´
 * {
 *     "Activities": {
 *          "type": "object",
 *          "x-object-alias": "full.namespace.object",
 *          "x-excel-sheet": "Activities",
 *          "properties: {
 *              "Activity_Id" {
 *                  "type": "string",
 *                  "x-attribute-alias": "attribute_alias",
 *                  "x-excel-column": "Activity_Id"
 *              }
 *          }
 *     }
 * }
 *
 * ´´´
 *
 *
 * Placeholder and STATIC Formulas can be defined wihtin the configuration.
 * "additional_rows": [
 *      {
 *          "attribute_alias": "ETLFlowRunUID",
 *          "value": "[#flow_run_uid#]"
 *      },
 *      {
 *          "attribute_alias": "RequestId",
 *          "value": "=Lookup('UID', 'axenox.ETL.webservice_request', 'flow_run = [#flow_run_uid#]')"
 *      },
 *      {
 *          "attribute_alias": "Betreiber",
 *          "value": "SuedLink"
 *      }
 * ]
 *
 * @author Andrej Kaqbachnik
 */
class ExcelApiToDataSheet extends JsonApiToDataSheet
{
    const API_SCHEMA_FORMAT = 'excel';
    const API_OPTION_SHEET = 'sheet';
    const API_OPTION_COLUMN = 'column';

    private $schemaName = null;
    private ?array $webservice = null;

    private $validateApiSchema = false;

    private $excelHasHeaderRow = true;

    /**
     *
     * {@inheritDoc}
     * @throws JSONPathException|\Throwable
     * @see \axenox\ETL\Interfaces\ETLStepInterface::run()
     */
    public function run(ETLStepDataInterface $stepData) : \Generator
    {
        $stepRunUid = $stepData->getStepRunUid();
        $placeholders = $this->getPlaceholders($stepData);
        $result = new UxonEtlStepResult($stepRunUid);
        $logBook = $this->getLogBook($stepData);
        $this->getCrudCounter()->reset();

        // Read the upload info (in particular the UID) into a data sheet
        $fileData = $this->getUploadData($stepData);
        $uploadUid = $fileData->getUidColumn()->getValue(0);;
        $placeholders['upload_uid'] = $uploadUid;

        // If there is no file to read, stop here.
        // TODO Or throw an error? Need a step config property here!
        if ($uploadUid === null) {
            $logBook->addLine($msg = 'No file found in step input');
            yield $msg . PHP_EOL;
            return $result->setProcessedRowsCounter(0);
        }

        // Create a FileInfo object for the Excel file
        $logBook->addSection('Reading from-sheet');
        $fileInfo = DataSourceFileInfo::fromObjectAndUID($fileData->getMetaObject(), $uploadUid);
        $logBook->addLine($msg = 'Processing file "' . $fileInfo->getFilename() . '"');
        yield $msg . PHP_EOL;

        $toObject = $this->getToObject();
        $toSheet = $this->createBaseDataSheet($placeholders);
        $apiSchema = $this->getAPISchema($stepData);
        $toObjectSchema = $apiSchema->getObjectSchema($toSheet->getMetaObject(), $this->getSchemaName());
        
        if ($this->isUpdateIfMatchingAttributes()) {
            $this->addDuplicatePreventingBehavior($this->getToObject());
        } elseif($toObjectSchema->isUpdateIfMatchingAttributes()) {
            $this->addDuplicatePreventingBehavior($toObject, $toObjectSchema->getUpdateIfMatchingAttributeAliases());
        }

        $fromSheet = $this->readExcel($fileInfo, $toObjectSchema);
        $logBook->addLine("Read {$fromSheet->countRows()} rows from Excel into from-sheet based on {$fromSheet->getMetaObject()->__toString()}");
        $logBook->addDataSheet('Excel data', $fromSheet->copy());
        $this->getCrudCounter()->addValueToCounter($fromSheet->countRows(), CrudCounter::COUNT_READS);
        
        // Validate data in the from-sheet against the JSON schema
        $logBook->addLine('`validate_api_schema` is `' . ($this->isValidatingApiSchema() ? 'true' : 'false') . '`');
        if ($this->isValidatingApiSchema()) {
            $logBook->addIndent(1);
            foreach ($fromSheet->getRows() as $i => $row) {
                $rowErrors = [];
                try {
                    $toObjectSchema->validateRow($row);
                } catch (JsonSchemaValidationError $e) {
                    $msg = $e->getMessage();
                    $logBook->addLine($msg);
                    $rowErrors[$i+1] = $msg;
                }
            }
            $logBook->addIndent(-1);
            if (count($rowErrors) > 0) {
                throw new RuntimeException('Invalid data on rows: ' . implode(', ', array_keys($rowErrors)));
            }
        }

        // Perform 'from_data_checks'.
        $this->performDataChecks($fromSheet, $this->getFromDataChecksUxon(), 'from_data_checks', $stepData, $logBook);
        $logBook->addDataSheet('From-Sheet', $fromSheet);
        if($fromSheet->countRows() === 0) {
            $msg = 'All from-rows removed by failed data checks. **Exiting step**.';
            yield $msg . PHP_EOL;
            $logBook->addLine($msg);

            $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
            return $result->setProcessedRowsCounter(0);
        }

        // Apply the mapper
        $logBook->addSection('Filling data sheet');
        $mapper = $this->getPropertiesToDataSheetMapper($fromSheet->getMetaObject(), $toObjectSchema);
        $toSheet = $this->applyDataSheetMapper($mapper, $fromSheet, $stepData, $logBook);

        if($toSheet->countRows() === 0) {
            $logBook->addLine($msg = 'All input rows removed because of invalid or missing data. **Exiting step**.');
            yield $msg . PHP_EOL;

            $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
            return $result->setProcessedRowsCounter(0);
        }
        
        $toSheet = $this->mergeBaseSheet($toSheet, $placeholders);
        $logBook->addDataSheet('To-data', $toSheet);

        $logBook->addSection('Saving data');
        $msg = 'Importing **' . $toSheet->countRows() . '** rows for ' . $toSheet->getMetaObject()->getAlias(). ' with the data from provided Excel file.';
        $logBook->addLine($msg);
        yield $msg;

        $this->getCrudCounter()->start([], false, [CrudCounter::COUNT_READS]);
        
        $writer = $this->writeData(
            $toSheet, 
            $this->getCrudCounter(), 
            $stepData,
            $logBook,
            $this->isSkipInvalidRows()
        );

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
     * Configure the underlying webservice that provides the OpenApi definition.
     *
     * @uxon-property webservice
     * @uxon-type object
     * @uxon-template {"alias": "alias", "version": "^1.25.x"}
     *
     * @param UxonObject $webserviceConfig
     * @return ExcelApiToDataSheet
     */
    protected function setWebservice(UxonObject $webserviceConfig) : ExcelApiToDataSheet
    {
        $this->webservice = $webserviceConfig->toArray();
        return $this;
    }

    protected function getWebserviceAlias() : ?string
    {
        return $this->webservice['alias'] ?? null;
    }

    protected function getWebserviceVersion() : ?string
    {
        return $this->webservice['version'] ?? null;
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
    protected function setSchemaName(string $schemaName) : ExcelApiToDataSheet
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
     * Reads the OpenAPI specification from the configrued webservice and transforms it into an excel column mapping
     * 
     * // TODO currently this supports only OpenAPI v3!!!
     * 
     * @return string
     */
    protected function getAPISchema(ETLStepDataInterface $stepData) : APISchemaInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice');
        $ds->getColumns()->addMultiple([
            'UID',
            'version',
            'swagger_json', 
            'type__schema_class',
            'enabled'
        ]);
        if ((null !== $customWebservice = $this->getWebserviceAlias()) && (null !== $customWebserviceVersion = $this->getWebserviceVersion())) {
            $ds->getFilters()->addConditionFromString('alias', $customWebservice, '==');
            $ds->getFilters()->addConditionFromString('version', $customWebserviceVersion, '==');
        } else {
            $ds->getFilters()->addConditionFromString('webservice_flow__flow__flow_run__UID', $stepData->getFlowRunUid());
        }
        $ds->dataRead();        

        switch ($ds->countRows()) {
            case 0:
                throw new RuntimeException('Cannot find webservice for flow step "' . $this->getName() . '" using filter `' . $ds->getFilters()->__toString() . '`');
            case 1:
                $row = $ds->getRow(0);
                break;
            default:
                $versionCol = $ds->getColumns()->get('version');
                $bestFit = SemanticVersionDataType::findVersionBest('*', $versionCol->getValues());
                $row = $ds->getRow($versionCol->findRowByValue($bestFit));
                break;
        }
        $schemaClass = $row['type__schema_class'];
        $schema = new $schemaClass($this->getWorkbench(), $row['swagger_json']);
        return $schema;
    }

    /**
     * 
     * @param \exface\Core\Interfaces\Tasks\TaskInterface $task
     */
    protected function getUploadData(ETLStepDataInterface $stepData) : DataSheetInterface
    {
        $task = $stepData->getTask();
        // If the task has input data and that data is a file, use it here.
        // Otherwise look for the flow run UID in the data of axenox.ETL.file_upload object.
        $fileData = $task->getInputData();
        if (
            $fileData !== null
            && ! $fileData->isEmpty()
            && $fileData->hasUidColumn(true)
            && ! $fileData->getMetaObject()->getBehaviors()->getByPrototypeClass(FileBehaviorInterface::class)->isEmpty()
        ) {
            $fileObj = $task->getMetaObject();
        } else {
            $fileObj = MetaObjectFactory::createFromString($this->getWorkbench(), 'axenox.ETL.file_upload');
            $fileData = DataSheetFactory::createFromObject($fileObj);
            $fileData->getFilters()->addConditionFromString('flow_run', $stepData->getFlowRunUid(), ComparatorDataType::EQUALS);
            $fileData->getColumns()->addFromUidAttribute();
            $fileData->dataRead();
        }
        return $fileData;
    }

    /**
     * 
     * @param \exface\Core\Interfaces\Filesystem\FileInfoInterface $fileInfo
     * @param array $toObjectSchema
     * @return DataSheetInterface
     */
    protected function readExcel(FileInfoInterface $fileInfo, APIObjectSchemaInterface $toObjectSchema) : DataSheetInterface
    {
        $sheetname = $toObjectSchema->getFormatOption(self::API_SCHEMA_FORMAT, self::API_OPTION_SHEET);
        // Create fake meta object with the expected attributes and use the regular
        // ExcelBuilder to read it.
        $fileAddress = $fileInfo->getPathAbsolute() . '/*[' . $sheetname . ']';
        $fakeObj = MetaObjectFactory::createTemporary(
            $this->getWorkbench(),
            'Temp. Excel',
            $fileAddress,
            ExcelBuilder::class,
            'exface.Core.objects_with_filebehavior'
        );
        // Improve excel reading performance by skipping empty cells. This will also help avoid
        // getting completely empty rows, that cannot be used for imports anyway.
        $fakeObj->setDataAddressProperty(ExcelBuilder::DAP_EXCEL_READ_EMPTY_CELLS, false);
        $this->getCrudCounter()->addObject($fakeObj);

        foreach ($toObjectSchema->getProperties() as $propSchema) {
            $excelColName = $propSchema->getFormatOption(self::API_SCHEMA_FORMAT, self::API_OPTION_COLUMN);
            if ($excelColName === null || $excelColName === '') {
                continue;
            }
            
            $attrAlias = $propSchema->getPropertyName();
            $excelAddress = '[' . $excelColName . ']';
            MetaObjectFactory::addAttributeTemporary(
                $fakeObj, 
                $attrAlias, 
                $excelColName,
                $excelAddress, 
                $propSchema->guessDataType()
            );
        }

        $fakeSheet = DataSheetFactory::createFromObject($fakeObj);
        $fakeSheet->getColumns()->addFromAttributeGroup($fakeObj->getAttributes());
        $fakeSheet->dataRead();
        return $fakeSheet;
    }

    /**
     * Set to FALSE to skip validaton of the Excel data against the API schema.
     * 
     * @uxon-property validate_api_schema
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $trueOrFalse
     * @return ExcelApiToDataSheet
     */
    protected function setValidateApiSchema(bool $trueOrFalse) : ExcelApiToDataSheet
    {
        $this->validateApiSchema = $trueOrFalse;
        return $this;
    }
    
    /**
     * 
     * @return bool
     */
    protected function isValidatingApiSchema() : bool
    {
        return $this->validateApiSchema;
    }

    /**
     * Set to FALSE if the excel table does NOT have a header row with column names
     * 
     * @uxon-property excel_has_header_row
     * @uxon-type boolean
     * @uxon-default true
     * 
     * @param bool $trueOrFalse
     * @return ExcelApiToDataSheet
     */
    protected function setExcelHasHeaderRow(bool $trueOrFalse) : ExcelApiToDataSheet
    {
        $this->excelHasHeaderRow = $trueOrFalse;
        return $this;
    }
    
    /**
     * {@inheritDoc}
     * @see JsonApiToDataSheet::getFromDataRowNumber()
     */
    protected function getFromDataRowNumber(int $dataSheetRowIdx): int
    {
        return $dataSheetRowIdx + 1 + ($this->excelHasHeaderRow ? 1 : 0);
    }
}