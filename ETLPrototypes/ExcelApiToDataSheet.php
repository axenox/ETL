<?php
namespace axenox\ETL\ETLPrototypes;

use axenox\ETL\Common\OpenAPI\OpenAPI3;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use exface\Core\CommonLogic\Filesystem\DataSourceFileInfo;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
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
 * @author miriam.seitz
 */
class ExcelApiToDataSheet extends JsonApiToDataSheet
{
    const API_SCHEMA_FORMAT = 'excel';
    const API_OPTION_SHEET = 'sheet';
    const API_OPTION_COLUMN = 'column';

    private $schemaName = null;
    private ?array $webservice = null;

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

        // Read the upload info (in particular the UID) into a data sheet
        $fileData = $this->getUploadData($stepData);
        $uploadUid = $fileData->getUidColumn()->getValue(0);;
        $placeholders['upload_uid'] = $uploadUid;

        // If there is no file to read, stop here.
        // TODO Or throw an error? Need a step config property here!
        if ($uploadUid === null) {
            yield 'No file found in step input' . PHP_EOL;
            return $result->setProcessedRowsCounter(0);
        }

        // Create a FileInfo object for the Excel file
        $fileInfo = DataSourceFileInfo::fromObjectAndUID($fileData->getMetaObject(), $uploadUid);
        yield 'Processing file "' . $fileInfo->getFilename() . '"' . PHP_EOL;

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
        $mapper = $this->getPropertiesToDataSheetMapper($fromSheet->getMetaObject(), $toObjectSchema);
        $toSheet = $mapper->map($fromSheet);
        $toSheet = $this->mergeBaseSheet($toSheet, $placeholders);

        // Saving relations is very complex and not yet supported for OpenApi Imports
        $this->removeRelationColumns($toSheet);

        yield 'Importing rows ' . $toSheet->countRows() . ' for ' . $toSheet->getMetaObject()->getAlias(). ' with the data from an Excel file import.';

        $transaction = $this->getWorkbench()->data()->startTransaction();
        try {
            // we only create new data in import, either there is an import table or a PreventDuplicatesBehavior
            // that can be used to update known entire
            $toSheet->dataCreate(false, $transaction);
        } catch (\Throwable $e) {
            $transaction->rollback();
            throw $e;
        }
        $transaction->commit();

        return $result->setProcessedRowsCounter($toSheet->countRows());
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
            'type__schema_json', 
            'swagger_json', 
            'enabled'
        ]);
        if ((null !== $customWebservice = $this->getWebserviceAlias()) && (null !== $customWebserviceVersion = $this->getWebserviceVersion())) {
            $ds->getFilters()->addConditionFromString('alias', $customWebservice, '==');
            $ds->getFilters()->addConditionFromString('version', $customWebserviceVersion, '==');
        } else {
            $ds->getFilters()->addConditionFromString('webservice_flow__flow__flow_run__UID', $stepData->getFlowRunUid());
        }
        $ds->dataRead();        

        $webservice = $ds->getSingleRow();
        $openApiJson = json_encode(OpenAPI3::enhanceSchema($webservice['swagger_json'], $this->getWorkbench()));
        return new OpenAPI3($this->getWorkbench(), $openApiJson);
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
        foreach ($toObjectSchema->getProperties() as $propSchema) {
            $excelColName = $propSchema->getFormatOption(self::API_SCHEMA_FORMAT, self::API_OPTION_COLUMN);
            if ($excelColName === null) {
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
}