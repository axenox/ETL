<?php
namespace axenox\ETL\ETLPrototypes;

use axenox\ETL\Common\AbstractAPISchemaPrototype;
use axenox\ETL\Common\Traits\PreventDuplicatesStepTrait;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
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
 * e.g "x-attribute-alias": "Objekt_ID"
 * If you want to link objects, use the id/uid in the original attribute.
 * e.g. "x-attribute-alias": "Request" -> '0x11EFBD3FD893913ABD3F005056BEF75D'
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
 * ## Additional columns and placeholders
 * 
 * Placeholder and STATIC Formulas can be defined wihtin the configuration.
 * 
 * ```
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
        $requestBody = $requestLogData['http_body'];

        if ($requestLogData['http_content_type'] !== 'application/json' || $requestBody === null) {
            yield 'No HTTP content found to process' . PHP_EOL;
            return $result->setProcessedRowsCounter(0);
        }

        $toObject = $this->getToObject();
        $apiSchema = $this->getAPISchema($task);
        $toObjectSchema = $apiSchema->getObjectSchema($toObject, $this->getSchemaName());
        
        if ($this->isUpdateIfMatchingAttributes()) {
            $this->addDuplicatePreventingBehavior($this->getToObject());
        }
        
        $routeSchema = $apiSchema->getRouteForRequest($task->getHttpRequest());
        $requestData = $routeSchema->parseData($requestBody, $toObject);

        $fromSheet = $this->readJson($requestData, $toObjectSchema);
        $mapper = $this->getMapper($fromSheet->getMetaObject(), $toObjectSchema);
        $toSheet = $mapper->map($fromSheet, false);
        $toSheet = $this->mergeBaseSheet($toSheet, $placeholders);

        // Saving relations is very complex and not yet supported for OpenApi Imports
        // TODO remove this?
        // $toSheet = $this->removeRelationColumns($toSheet);

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

    protected function getMapper(MetaObjectInterface $fromObj, APIObjectSchemaInterface $toObjectSchema) : DataSheetMapperInterface
    {
        $col2col = [];
        $lookups = [];
        foreach ($toObjectSchema->getProperties() as $propName => $propSchema) {
            switch (true) {
                case null !== $lookup = $propSchema->getLookupUxon():
                    if (null !== $attr = $propSchema->getAttribute()) {
                        $lookup->setProperty('to', $attr->getAliasWithRelationPath());
                    }
                    $lookups[] = $lookup;
                    break;
                case null !== $attr = $propSchema->getAttribute():
                    $col2col[] = [
                        'from' => $propName,
                        'to' => $attr->getAlias()
                    ];
                    break;
            }
        }
        $uxon = new UxonObject([
            'from_object_alias' => $fromObj->getAliasWithNamespace(),
            'to_object_alias' => $toObjectSchema->getMetaObject()->getAliasWithNamespace()
        ]);
        if (null !== $customMapperUxon = $this->getMapperUxon()) {
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
     * Custom mapper 
     * 
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheetMapper;
     * @uxon-property mapper
     * @uxon-template {"column_to_column_mappings": [{"from": "", "to": ""}]}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return JsonApiToDataSheet
     */
    protected function setMapper(UxonObject $uxon) : JsonApiToDataSheet 
    {
        $this->mapperUxon = $uxon;
        return $this;
    }

    protected function getMapperUxon() : ?UxonObject
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
    protected function readJson(array $data, APIObjectSchemaInterface $toObjectSchema, string $objectAlias = 'exface.Core.DUMMY') : DataSheetInterface
    {
        $dataSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $objectAlias);
        $neededProperties = $toObjectSchema->getPropertyNames();

        if (ArrayDataType::isSequential($data)) {
            // Named array: { "object-key" [ {"id": "123", "name": "abc" }, {"id": "234", "name": "cde"} ] }
            // Unnamed array: [ {"id": "123", "name": "abc" }, {"id": "234", "name": "cde"} ]
            foreach ($data as $entry) {
                $row = $this->readJsonRow($entry, $neededProperties);
                $dataSheet->addRow($row);
            }
        } else {
            // Named object: { "object-key" {"id": "123", "name": "abc" } }
            // Unnamed object: {"id": "123", "name": "abc" }
            $row = $this->readJsonRow($data, $neededProperties);
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

    protected function setAllowDataMappers(bool $trueOrFalse) : JsonApiToDataSheet
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