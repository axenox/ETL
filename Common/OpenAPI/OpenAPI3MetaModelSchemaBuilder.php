<?php
namespace axenox\ETL\Common\OpenAPI;

use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\HexadecimalNumberDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\TimeDataType;
use exface\Core\Exceptions\Model\MetaModelLoadingFailedError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\BinaryDataType;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * A builder that creates a schema from a MetaObjectInterface.
 * 
 * @author miriam.seitz
 *
 */
class OpenAPI3MetaModelSchemaBuilder
{
    private bool $onlyReturnProperties;

    private bool $forceSchema;

    private bool $loadExamples;

    private ?array $relationObjectsToLoad;

    /**
     * Create the builder and configure the creation of a json schema.
     *
     * @param array|null $relationObjects adds these attributes as references - they must be present in the schema json!
     * @param bool $onlyReturnProperties configure if the response will be wrapped in the attribute alias with namespace
     * @param bool $forceSchema configure if the schema will set all properties required and does not allow additional properties
     * @param bool $loadExamples configure if every property will get an example
     */
    public function __construct(
        ?array $relationObjects = null,
        bool   $onlyReturnProperties = false,
        bool   $forceSchema = false,
        bool   $loadExamples = false)
    {
        $this->onlyReturnProperties = $onlyReturnProperties;
        $this->forceSchema = $forceSchema;
        $this->loadExamples = $loadExamples;
        $this->relationObjectsToLoad = $relationObjects;
    }

    /**
     * Create a json schema for the given meta object. Uses all DataType classes supported by JsonSchema.
     *
     * @param MetaObjectInterface $metaObject
     * @return array
     */
    public function transformIntoJsonSchema(MetaObjectInterface $metaObject): array
    {
        $objectName = $metaObject->getAliasWithNamespace();
        if ($this->onlyReturnProperties) {
            $jsonSchema = [
                'type' => 'object',
                'x-object-alias' => $objectName,
                'properties' => []];
            $subArray = &$jsonSchema['properties'];
        } else {
            $jsonSchema = [$objectName => [
                'type' => 'object',
                'x-object-alias' => $objectName,
                'properties' => []]];
            $subArray = &$jsonSchema[$objectName]['properties'];
        }

        if ($this->loadExamples) {
            try {
                $richestRow = self::getExampleRow(
                    $metaObject, 
                    $metaObject->getAliasWithNamespace(), 
                    $metaObject->getAttributes()->getAll(),
                    true,
                    -1
                );
            } catch (\Throwable $exception) {
                $metaObject->getWorkbench()->getLogger()->logException(new MetaModelLoadingFailedError(
                    'Could not load example data for "' . $metaObject->getAliasWithNamespace() . '".',
                    null,
                    $exception
                ));
            }
        }

        $properties = [];
        foreach ($metaObject->getAttributes() as $attribute) {
            $schema = [];
            
            if ($attribute->isRelation()) {
                $relatedObjectAlias = $attribute->getRelation()
                    ->getRightObject()
                    ->getAliasWithNamespace();
                if (empty($this->relationObjectsToLoad) === false
                    && in_array($relatedObjectAlias, $this->relationObjectsToLoad)) {
                    $schema = ['$ref' => '#/components/schemas/Metamodel Informationen/properties/' . $relatedObjectAlias];
                    $subArray[$attribute->getAlias()] = $schema;
                    continue;
                }
            }
            
            $properties[] = $attribute->getAlias();
            $schema = array_merge($schema, self::convertToJsonSchemaDatatype($attribute->getDataType()));
            
            if ($attribute->isRequired() === false){
                $schema['nullable'] = true;
            }

            if ($attribute->getHint() !== $attribute->getName()) {
                $schema['description'] = $attribute->getHint();
            }

            if (empty($richestRow) === false && ($rowValue = $richestRow[$attribute->getAlias()]) !== null){
                $schema['example'] = $rowValue;
            }

            if ($attribute->isRelation()) {
                $schema['x-foreign-key'] = $attribute->getDataAddress();
            }

            $schema['x-attribute-alias'] = $attribute->getAlias();

            $subArray[$attribute->getAlias()] = $schema;
        }

        if ($this->forceSchema) {
            $jsonSchema['additionalProperties'] = false;
            $jsonSchema['required'] = $properties;
        }

        return $jsonSchema;
    }

    /**
     * Returns an example row with all the attributes provided.
     *
     * NOTE: Results will be stored in cache and are kept for up to one hour.
     * If you wish to refresh your examples sooner than that, clear the cache.
     *
     * @param MetaObjectInterface $metaObject
     * @param string              $cacheKey
     * @param array               $attributes
     * @param bool                $stitchData
     * If TRUE, the returned row actually exists in the source data. If FALSE the resulting row will be stitched
     * together from multiple rows to maximize the number of columns containing meaningful data.
     * @param int                 $count
     * The number of rows to be checked for example data. Rows will be loaded from the bottom of the source data.
     * Use -1 to load all available source data.
     * @return array|null
     */
    public static function getExampleRow(
        MetaObjectInterface $metaObject,
        string              $cacheKey,
        array               $attributes,
        bool                $stitchData,
        int                 $count
    ) : ?array
    {
        $fromCache = self::loadExamplesFromCache($metaObject, $cacheKey);
        if($fromCache !== null) {
            return $fromCache;
        }

        $columns = [];
        $labelColumns = [];
        foreach ($attributes as $attr) {
            $shortAlias = $attr->getAlias();
            if($attr->isRelation()) {
                $columns[$shortAlias] = $attr->getAliasWithRelationPath();
                if($attr->getRelation()->getRightObject()->hasLabelAttribute()) {
                    $labelAlias = $shortAlias . '__LABEL';
                    $labelColumns[$labelAlias] = $labelAlias;
                }
            } else {
                $columns[$shortAlias] = $shortAlias;
            }

        }

        $ds = DataSheetFactory::createFromObject($metaObject);

        if (($tsBehavior = $metaObject->getBehaviors()->getByPrototypeClass(TimeStampingBehavior::class)->getFirst()) != null){
            $orderingAttribute = $tsBehavior->getUpdatedOnAttribute();
            $ds->getSorters()->addFromString($orderingAttribute->getAlias(), 'DESC');
        }

        if(!$ds->hasUidColumn()) {
            if($metaObject->hasUidAttribute()) {
                $ds->getColumns()->addFromAttribute($metaObject->getUidAttribute());
            } else {
                return null;
            }
        }

        $rowWithRelations = $stitchData ?
            self::getStitchedRow($ds, $columns, $labelColumns) : 
            self::getRowWithTheLeastNullValues($ds, $columns, $labelColumns, $count);

        if($rowWithRelations === null) {
            return null;
        }

        $rowHideRelations = [];
        foreach ($columns as $shortAlias => $fullAlias) {
            $rowHideRelations[$shortAlias] = $rowWithRelations[$fullAlias] ?? $rowWithRelations[$shortAlias];
        }

        self::storeExamplesInCache($metaObject, $cacheKey, $rowHideRelations);
        return $rowHideRelations;
    }

    /**
     * @param MetaObjectInterface $metaObject
     * @param string              $key
     * @return array|null
     */
    private static function loadExamplesFromCache(MetaObjectInterface $metaObject, string $key) : ?array
    {
        $cacheKey = self::getCacheKey($key);
        $cache = $metaObject->getWorkbench()->getCache();
        
        try {
            if (!$cache->has($cacheKey)) {
                return null;
            }
            
            $fromCache = $cache->get($cacheKey);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            throw new MetaModelLoadingFailedError($e->getMessage(), '83K3MPU', $e);
        }

        if($fromCache['expires'] < time()) {
            return null;
        }
        
        return $fromCache['row'];
    }

    /**
     * @param MetaObjectInterface $metaObject
     * @param string              $key
     * @param array               $row
     * @return void
     */
    private static function storeExamplesInCache(
        MetaObjectInterface $metaObject,
        string              $key,
        array               $row 
    ) : void
    {
        try {
            $metaObject->getWorkbench()->getCache()->set(
                self::getCacheKey($key),
                [
                    'expires' => time() + 3600,
                    'row' => $row,
                ]
            );
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            throw new MetaModelLoadingFailedError($e->getMessage(), '83K3MPU', $e);
        }
    }

    /**
     * @param string $key
     * @return string
     */
    private static function getCacheKey(string $key) : string
    {
        return 'SchemaExamples__' . $key;
    }

    private static function getStitchedRow(
        DataSheetInterface $dataSheet, 
        array $columns,
        array $labelColumns
    ) : array
    {
        $result = [];
        
        foreach ($columns as $column) {
            $columnSheet = $dataSheet->copy();
            $columnSheet->getColumns()->addFromExpression($column);
            $columnSheet->getFilters()->addConditionFromString(
                $column,
                EXF_LOGICAL_NULL,
                ComparatorDataType::IS_NOT
            );
            
            $labelColumn = $column . '__LABEL';
            if(key_exists($labelColumn, $labelColumns)) {
                $columnSheet->getColumns()->addFromExpression($labelColumn);
            }

            try {
                $columnSheet->dataRead(1);
                $row = $columnSheet->getRow();
                if(!empty($row)) {
                    $result[$column] = $row[$labelColumn] ?? $row[$column];
                }
            } catch (\Throwable $e) {

            }
        }
        
        return $result;
    }

    /**
     * @param DataSheetInterface $dataSheet
     * @param array              $columns
     * @param array              $labelColumns
     * @param int                $count
     * @return mixed|null
     */
    private static function getRowWithTheLeastNullValues(
        DataSheetInterface $dataSheet, 
        array $columns,
        array $labelColumns,
        int $count
    ) : ?array
    {
        $dataSheet->getColumns()->addMultiple($columns);
        $dataSheet->getColumns()->addMultiple($labelColumns);
        try {
            $dataSheet->dataRead($count > 0 ? $count : null);
        } catch (\Throwable $e) {

        }
        
        $bestRow = null;
        $mostValues = 0;
        
        foreach ($dataSheet->getRows() as $row) {
            $values = 0;
            foreach ($columns as $column) {
                $labelColumn = $column . '__LABEL';
                $value = $row[$labelColumn] ?? $row[$column];

                if ($value !== null) {
                    $values++;
                }
            }
            
            if($values > $mostValues) {
                $bestRow = $row;
                $mostValues = $values;
            }
        }
        
        return $bestRow;
    }

    /**
     * Convert a given data type to one that is JSON conform.
     * 
     * @param DataTypeInterface $dataType
     * @return array|string[]
     */
    public static function convertToJsonSchemaDatatype(DataTypeInterface $dataType) : array
    {
        switch (true) {
            case $dataType instanceof IntegerDataType:
            case $dataType instanceof TimeDataType:
                return ['type' => 'integer'];
            case ($dataType instanceof NumberDataType) && $dataType->getBase() === 10:
                return ['type' => 'number'];
            case $dataType instanceof BooleanDataType:
                return ['type' => 'boolean'];
            case $dataType instanceof ArrayDataType:
                return ['type' => 'array'];
            case $dataType instanceof EnumDataTypeInterface:
                return ['type' => 'string', 'enum' => $dataType->getValues()];
            case $dataType instanceof DateTimeDataType:
                return ['type' => 'string', 'format' => 'datetime'];
            case $dataType instanceof DateDataType:
                return ['type' => 'string', 'format' => 'date'];
            case $dataType instanceof BinaryDataType:
                if ($dataType->getEncoding() == 'base64') {
                    return ['type' => 'string', 'format' => 'byte'];
                } else {
                    return ['type' => 'string', 'format' => 'binary'];
                }
            case $dataType instanceof StringDataType:
                return ['type' => 'string'];
            case $dataType instanceof HexadecimalNumberDataType:
                return ['type' => 'string'];
            default:
                throw new InvalidArgumentException('Datatype: ' . $dataType->getAlias() . ' not recognized.');
        }
    }
}