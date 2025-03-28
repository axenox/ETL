<?php
namespace axenox\ETL\ETLPrototypes;

use axenox\ETL\Common\AbstractOpenApiPrototype;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\DataSheetFactory;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use exface\Core\Factories\DataSheetMapperFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSheets\DataSheetMapperInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Tasks\HttpTaskInterface;
use axenox\ETL\Events\Flow\OnBeforeETLStepRun;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use Flow\JSONPath\JSONPathException;
use axenox\ETL\Common\UxonEtlStepResult;

/**
 * Objects have to be defined with an x-object-alias and with x-attribute-aliases like:
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
 * e.g "x-attribute-alias": "Objekt_ID"
 * If you want to link objects, use the id/uid in the original attribute.
 * e.g. "x-attribute-alias": "Request" -> '0x11EFBD3FD893913ABD3F005056BEF75D'
 *
 * The from-object HAS to be defined within the request schema of the route to the step!
 * e.g. with multiple structural concepts
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
class OpenApiJsonToDataSheet extends AbstractOpenApiPrototype
{
    private $additionalColumns = null;
    private $schemaName = null;
    private $allowMappers = null;

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
        $task = $stepData->getTask();

        if (! ($task instanceof HttpTaskInterface)){
            throw new InvalidArgumentException('Http request needed to process OpenApi definitions! `' . get_class($task) . '` received instead.');
        }
        
        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeETLStepRun($this));

        $requestLogData = $this->loadRequestData($stepData, ['http_body', 'http_content_type'])->getRow(0);
        $requestBody = json_decode($requestLogData['http_body'], true);

        if ($requestLogData['http_content_type'] !== 'application/json' || $requestBody === null) {
            yield 'No HTTP content found to process' . PHP_EOL;
            return $result->setProcessedRowsCounter(0);
        }

        $openApiJson = $this->getOpenApiJson($stepData->getTask());
        $toSheet = $this->createBaseDataSheet($placeholders);
    	$toObjectSchema = $this->getOpenApiSchemaForObject($toSheet->getMetaObject(), $openApiJson, $this->getSchemaName());
        $toSheet->getColumns()->addFromSystemAttributes();
        
        $requestSchema = $this->getOpenApiSchemaForRequest($task->getHttpRequest(), $openApiJson);
        $key = $this->getArrayKeyToImportDataFromSchema($requestSchema, $toObjectSchema[self::OPEN_API_ATTRIBUTE_TO_OBJECT_ALIAS]);
        if ($this->getAllowDataMappers()) {
            $fromSheet = $this->readJson($requestBody, $toObjectSchema, $key);
            $mapper = $this->getMapper($fromSheet->getMetaObject(), $toSheet->getMetaObject(), $toObjectSchema);
            $mappedSheet = $mapper->map($fromSheet, false);
            $toSheet->merge($mappedSheet);
        } else {
            $this->fillDataSheetWithImportData($toSheet, $requestBody, $toObjectSchema, $placeholders, $key);
        }

        // Saving relations is very complex and not yet supported for OpenApi Imports
        // TODO remove this?
        $this->removeRelationColumns($toSheet);

        yield 'Importing rows ' . $toSheet->countRows() . ' for ' . $toSheet->getMetaObject()->getAlias(). ' with the data sent via webservice request.';

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
     * Searches through the request schema looking for the object reference and returning its name.
     * This key can than be used to find the object within the request body.
     *
     * @param array $requestSchema
     * @param string $objectAlias
     * @return string|null
     */
    protected function getArrayKeyToImportDataFromSchema(array $requestSchema, string $objectAlias) : ?string
    {
        $key = null;
        switch ($requestSchema['type']) {
            case 'array':
                $key = $this->getArrayKeyToImportDataFromSchema($requestSchema['items'], $objectAlias);
                break;
            case 'object':
            foreach ($requestSchema['properties'] as $propertyName => $propertyValue) {
                switch (true) {
                    case array_key_exists('x-object-alias', $propertyValue) && $propertyValue['x-object-alias'] === $objectAlias:
                        return $propertyName;
                    case $propertyValue['type'] === 'array':
                    case $propertyValue['type'] === 'object':
                        $key = $this->getArrayKeyToImportDataFromSchema($propertyValue, $objectAlias);
                        break;
                }
            }
        }

        return $key;
    }

    protected function getMapper(MetaObjectInterface $fromObj, MetaObjectInterface $toObj, array $toObjectSchema) : DataSheetMapperInterface
    {
        $col2col = [];
        $lookups = [];
        foreach ($toObjectSchema['properties'] as $propName => $propCfg) {
            switch (true) {
                case null !== $lookup = $propCfg[self::OPEN_API_ATTRIBUTE_LOOKUP]:
                    if (null !== $attrAlias = $propCfg[self::OPEN_API_ATTRIBUTE_TO_ATTRIBUTE_ALIAS]) {
                        $lookup['to'] = $attrAlias;
                    }
                    $lookups[] = $lookup;
                    break;
                case null !== $attrAlias = $propCfg[self::OPEN_API_ATTRIBUTE_TO_ATTRIBUTE_ALIAS]:
                    $col2col[] = [
                        'from' => $propName,
                        'to' => $attrAlias
                    ];
                    break;
            }
        }
        $uxon = new UxonObject([
            'from_object_alias' => $fromObj->getAliasWithNamespace(),
            'to_object_alias' => $toObj->getAliasWithNamespace()
        ]);
        if (! empty($col2col)) {
            $uxon->setProperty('column_to_column_mappings', new UxonObject($col2col));
        }
        /*
        if (! empty($lookups)) {
            $uxon->setProperty('lookup_mappings', $lookups);
        }*/
        // TODO Add DataColumnToJsonMapping's here
        return DataSheetMapperFactory::createFromUxon($this->getWorkbench(), $uxon);
    }

    /**
     * 
     * @param array $requestBody
     * @param array $toObjectSchema
     * @param string|null $key
     * @param string $objectAlias
     * @return DataSheetInterface
     */
    protected function readJson(array $requestBody, array $toObjectSchema, ?string $key = null, string $objectAlias = 'exface.Core.DUMMY') : DataSheetInterface
    {
        $dataSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $objectAlias);
        $neededProperties = array_keys($toObjectSchema['properties']);

        // Determine if the request body contains a named array/object or an unnamed array/object
        $sourceData = is_array($requestBody[$key]) ? $requestBody[$key] : $requestBody;

        if (ArrayDataType::isSequential($sourceData)) {
            // Named array: { "object-key" [ {"id": "123", "name": "abc" }, {"id": "234", "name": "cde"} ] }
            // Unnamed array: [ {"id": "123", "name": "abc" }, {"id": "234", "name": "cde"} ]
            foreach ($sourceData as $entry) {
                $row = $this->readJsonRow($entry, $neededProperties);
                $dataSheet->addRow($row);
            }
        } else {
            // Named object: { "object-key" {"id": "123", "name": "abc" } }
            // Unnamed object: {"id": "123", "name": "abc" }
            $row = $this->readJsonRow($sourceData, $neededProperties);
            $dataSheet->addRow($row);
        }
        return $dataSheet;
    }

    /**
     * 
     * @param array $bodyLine
     * @param string[] $neededProperties
     * @return string[]
     */
    protected function readJsonRow(array $bodyLine, array $neededProperties) : array
    {
        $importData = [];
        foreach ($bodyLine as $propertyName => $value) {
            switch(true) {
                case in_array($propertyName, $neededProperties) === false && is_array($value):
                case is_numeric($propertyName):
                    $importData = $this->readJsonRow($value, $neededProperties);
                    break;
                case in_array($propertyName, $neededProperties):
                    // arrays and objects are represented via string in the database
                    if (is_array($value)) {
                        $value =  trim(json_encode($value), '[]');
                    }

                    if (is_object($value)) {
                        $value =  json_encode($value);
                    }

                    $importData[$propertyName] = $value;
                    break;
            }
        }

        return $importData;
    }

    /**
     * Reads import data from the request body. If no key is specified, it will search the response body for the right object.
     * Otherwise, it will try to read the whole request body content into the requested datasheet for the object of this step.
     *
     * @param mixed $requestBody
     * @param array $toObjectSchema
     * @param array $placeholder
     * @param DataSheetInterface $dataSheet
     * @param string|null $key
     * @return void
     */
    protected function fillDataSheetWithImportData(
        DataSheetInterface $dataSheet,
        array $requestBody,
        array $toObjectSchema,
        array $placeholder,
        ?string $key = null) : void
    {
        $attributeAliasByPropertyName = [];
        foreach ($toObjectSchema['properties'] as $propertyName => $propertyValue) {
            $attributeAliasByPropertyName[$propertyName] = $propertyValue[self::OPEN_API_ATTRIBUTE_TO_ATTRIBUTE_ALIAS];
        }

        // Determine if the request body contains a named array/object or an unnamed array/object
        $sourceData = is_array($requestBody[$key]) ? $requestBody[$key] : $requestBody;

        if (ArrayDataType::isSequential($sourceData)) {
            // Named array: { "object-key" [ {"id": "123", "name": "abc" }, {"id": "234", "name": "cde"} ] }
            // Unnamed array: [ {"id": "123", "name": "abc" }, {"id": "234", "name": "cde"} ]
            $rowIndex = 0;
            foreach ($sourceData as $entry) {
                $row = $this->getImportDataFromRequestBody($entry, $attributeAliasByPropertyName);
                $this->addRowToDataSheetWithAdditionalColumns($dataSheet, $placeholder, $row, $rowIndex);
                $rowIndex++;
            }
        } else {
            // Named object: { "object-key" {"id": "123", "name": "abc" } }
            // Unnamed object: {"id": "123", "name": "abc" }
            $row = $this->getImportDataFromRequestBody($sourceData, $attributeAliasByPropertyName);
            $this->addRowToDataSheetWithAdditionalColumns($dataSheet, $placeholder, $row, 0);
        }
    }

    /**
     * @param $requestBody
     * @param $attributeAliasByPropertyName
     * @return array
     */
    protected function getImportDataFromRequestBody($requestBody, $attributeAliasByPropertyName) : array
    {
        $importData = [];
        foreach ($requestBody as $propertyName => $value) {
            switch(true) {
                case array_key_exists($propertyName, $attributeAliasByPropertyName) === false && is_array($value):
                case is_numeric($propertyName):
                    $importData = $this->getImportDataFromRequestBody($value, $attributeAliasByPropertyName);
                    break;
                case array_key_exists($propertyName, $attributeAliasByPropertyName):
                    // arrays and objects are represented via string in the database
                    if (is_array($value)) {
                        $value =  trim(json_encode($value), '[]');
                    }

                    if (is_object($value)) {
                        $value =  json_encode($value);
                    }

                    $importData[$attributeAliasByPropertyName[$propertyName]] = $value;
                    break;
            }
        }

        return $importData;
    }

    /**
     * Add additional columns to the to-object that are filled in every row by this configuration or placeholders.
     *
     * e.g.
     * "additional_rows": [
     *      {
     *           "attribute_alias": "RequestId",
     *           "value": "[#Request#]"
     *      },
     *      {
     *          "attribute_alias": "RequestId",
     *          "value": "=Lookup('UID', 'axenox.ETL.webservice_request', 'flow_run = [#flow_run_uid#]')"
     *      },
     *      {
     *           "attribute_alias": "Betreiber",
     *           "value": "SuedLink"
     *      }
     * ]
     *
     * @uxon-property additional_columns
     * @uxon-type object
     * @uxon-template [{"attribute_alias":"", "value": ""}]
     *
     * @param UxonObject $additionalColumns
     * @return OpenApiJsonToDataSheet
     */
    protected function setAdditionalColumns(UxonObject $additionalColumns) : OpenApiJsonToDataSheet
    {
        $this->additionalColumns = $additionalColumns->toArray();
        return $this;
    }

    protected function getAdditionalColumn() : array
    {
        return $this->additionalColumns;
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
    protected function setSchemaName(string $schemaName) : OpenApiJsonToDataSheet
    {
        $this->schemaName = $schemaName;
        return $this;
    }

    protected function getSchemaName() : ?string
    {
        return $this->schemaName;
    }

    protected function setAllowDataMappers(bool $trueOrFalse) : OpenApiJsonToDataSheet
    {
        $this->allowMappers = $trueOrFalse;
        return $this;
    }

    protected function getAllowDataMappers() : bool
    {
        return $this->allowMappers ?? true;
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
}