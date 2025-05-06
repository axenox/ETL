<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIPropertyInterface;
use exface\Core\CommonLogic\Model\CustomAttribute;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\BinaryDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\StringEnumDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\ExpressionFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\ExpressionInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;

/**
 * Represents an OpenAPI 3.x property with additional annotations connecting it to the meta model
 * 
 * Allows the following cusotm OpenAPI properties:
 * 
 * - `x-attribute-alias` - the meta model attribute this property is bound to
 * - `x-lookup` - the Uxon object to look up for this property
 * - `x-calculation` - the calculation expression for this property
 * - `x-custom-attribute` - create a custom attribute for the object right here
 * 
 * @author  Andrej Kabachnik
 */
class OpenAPI3Property implements APIPropertyInterface
{
    use OpenAPI3UxonTrait;
    
    const X_ATTRIBUTE_ALIAS = 'x-attribute-alias';
    const X_ATTRIBUTE_GROUP_ALIAS = 'x-attribute-group-alias';
    const X_LOOKUP = 'x-lookup';
    const X_CALCULATION = 'x-calculation';
    const X_CUSTOM_ATTRIBUTE = 'x-custom-attribute';
    const X_PROPERTIES_FROM_DATA = 'x-properties-from-data';

    private $objectSchema = null;
    private $name = null;
    private $jsonSchema = null;
    private $attribute = null;
    private $workbench = null;

    public function __construct(OpenAPI3ObjectSchema $objectSchema, string $propertyName, array $jsonSchema)
    {
        $this->name = $propertyName;
        $this->objectSchema = $objectSchema;
        $this->jsonSchema = $jsonSchema;
        $this->workbench = $objectSchema->getAPI()->getWorkbench();
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getObjectSchema()
     */
    public function getObjectSchema(): APIObjectSchemaInterface
    {
        return $this->objectSchema;
    }
    
    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getPropertyName()
     */
    public function getPropertyName() : string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::hasLookup()
     */
    public function hasLookup() : bool
    {
        return null !== $this->jsonSchema[self::X_LOOKUP] ?? null;
    }

    /**
     * Use the property to lookup a value in the data of a meta object instead of using the property value directly
     * 
     * ## Examples:
     * 
     * ### Lookup the UID of a country while receiving its name
     * 
     * The data received by the web service will expect the name of a country in the property `Country`, but we need
     * to write the UID of that country into our data. The `x-lookup` custom property describes, how to find the
     * UID using the name.
     * 
     * In this simple scenario, the UID will be used instead of the name and will be saved to the `COUNTRY` attribute
     * of the to-object.
     * 
     * ```
     * {
     *      "Country": {
     *          "type": "string",
     *          "nullable": true,
     *          "example": "Germany",
     *          "x-attribute-alias": "COUNTRY",
     *          "x-lookup": {
     *              "lookup_object_alias": "my.APP.COUNTRY",
     *              "lookup_column": "UID",
     *              "matches": [
     *                  {
     *                      "from": "Country",
     *                      "lookup": "NAME"
     *                  }
     *              ]
     *          }
     *      }
     * }
     * 
     * ```
     * 
     * ### Lookup the UID of a country by name, but keep both: name and UID
     * 
     * In this example, we want to keep the name of the country in the attribute `CONTRY_NAME` and place the UID next to it
     * into the attribute "COUNTRY". Our attribute binding (`x-attribute-alias`) is `COUNTRY_NAME` in this case, while the
     * `x-lookup` now explicitly specifies a `to`-column to place the UID into. 
     * 
     * ```
     * {
     *      "Country": {
     *          "type": "string",
     *          "nullable": true,
     *          "example": "Germany",
     *          "x-attribute-alias": "COUNTRY_NAME",
     *          "x-lookup": {
     *              "lookup_object_alias": "my.APP.COUNTRY",
     *              "lookup_column": "UID",
     *              "to": "COUNTRY",
     *              "matches": [
     *                  {
     *                      "from": "Country",
     *                      "lookup": "NAME"
     *                  }
     *              ]
     *          }
     *      }
     * }
     * 
     * ```
     * 
     * @uxon-property x-lookup
     * @uxon-type \exface\Core\CommonLogic\DataSheets\Mappings\LookupMapping
     * @uxon-template {"lookup_object_alias":"// Look in this object","lookup_column":"// Take this value from the lookup-object and put it into the property attribute","matches":[{"from":"// OpenAPI property","lookup":"// column in the lookup data"}]}
     * 
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getLookupUxon()
     */
    public function getLookupUxon() : ?UxonObject
    {
        if (! $this->hasLookup()) {
            return null;
        }
        return new UxonObject($this->jsonSchema[self::X_LOOKUP]);
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::isBoundToAttribute()
     */
    public function isBoundToAttribute() : bool
    {
        return null !== $this->getAttributeAlias();
    }

    /**
     * The meta model attribute this property is bound to
     * 
     * @uxon-property x-attribute-alias
     * @uxon-type metamodel:attribute
     *
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getAttributeAlias()
     */
    public function getAttributeAlias() : ?string
    {
        return $this->jsonSchema[self::X_ATTRIBUTE_ALIAS] ?? null;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::isBoundToAttributeGroup()
     */
    public function isBoundToAttributeGroup() : bool
    {
        return null !== $this->getAttributeAlias();
    }

    /**
     * The meta model attribute group this property originates from
     * 
     * @uxon-property x-attribute-group-alias
     * @uxon-type metamodel:attribute_group
     *
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getAttributeGroupAlias()
     */
    public function getAttributeGroupAlias() : ?string
    {
        return $this->jsonSchema[self::X_ATTRIBUTE_GROUP_ALIAS] ?? null;
    }

    /**
     * 
     */
    public function isBoundToData() : bool
    {
        return null !== $this->getDataSheetToLoadProperties();
    }

    /**
     * The meta model attribute this property is bound to
     * 
     * @uxon-property x-properties-from-data
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheet
     * @uxon-template {"object_alias": "", "columns": [{"attribute_alias": ""}]}
     * 
     * @return DataSheetInterface|null
     */
    public function getDataSheetToLoadProperties() : ?DataSheetInterface
    {
        $json = $this->jsonSchema[self::X_PROPERTIES_FROM_DATA] ?? null;
        if (empty($json)) {
            return null;
        }
        $uxon = UxonObject::fromAnything($json);
        if (! $uxon->isEmpty()) {
            return DataSheetFactory::createFromUxon($this->objectSchema->getMetaObject()->getWorkbench(), $uxon);
        }
        return null;
    }

    /**
     * Create a custom attribute for the object right here and use any data address - even an SQL query
     * 
     * **NOTE:** This property will not be visible in the OpenAPI json or Swagger UI!
     * 
     * @uxon-property x-custom-attribute
     * @uxon-type \exface\core\CommonLogic\Model\CustomAttribute
     * @uxon-template {"alias":"// The alias of the attribute","data_address":"// The data address of the attribute","data_type":"exface.Core.String"}
     * 
     * @return string|null
     */
    protected function getAttributeCustomUxon() : ?UxonObject
    {
        $model = $this->jsonSchema[self::X_CUSTOM_ATTRIBUTE] ?? null;
        if ($model === null) {
            return null;
        }
        return new UxonObject($model);
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getAttribute()
     */
    public function getAttribute() : ?MetaAttributeInterface
    {
        if ($this->attribute === null) {
            $alias = $this->getAttributeAlias();
            $customUxon = $this->getAttributeCustomUxon();
            if ($customUxon !== null) {
                if ($alias === null && $customUxon->hasProperty('alias')) {
                    $alias = $customUxon->getProperty('alias');
                }
                $this->attribute = new CustomAttribute($this->getObjectSchema()->getMetaObject(), $alias, $alias, $this);
                $this->attribute->importUxonObject($customUxon);
            } else {
                $this->attribute = $this->getObjectSchema()->getMetaObject()->getAttribute($alias);
            }
        }
        return $this->attribute;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::isBoundToFormat()
     */
    public function isBoundToFormat(string $format) : bool
    {
        foreach (array_keys($this->jsonSchema) as $prop) {
            if (StringDataType::startsWith($prop, 'x-' . $format . '-')) {
                return true;
            }
        }
        return false;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getFormatOption()
     */
    public function getFormatOption(string $format, string $option) : mixed 
    {
        $value = $this->jsonSchema['x-' . $format . '-' . $option] ?? null;
        if (is_string($value)) {
            $value = $this->replacePlaceholders($value);
            $value = $this->evaluateFormulas($value);
        }
        return $value;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getPropertyType()
     */
    public function getPropertyType() : string
    {
        return $this->jsonSchema['type'];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::guessDataType()
     */
    public function guessDataType() : DataTypeInterface
    {
        return $this->findDataType($this->jsonSchema['type'], $this->jsonSchema['format'], $this->jsonSchema['enum']);
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::isBoundToMetamodel()
     */
    public function isBoundToMetamodel() : bool
    {
        return $this->isBoundToAttribute();
    }

    /**
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::isBoundToCalculation()
     */
    public function isBoundToCalculation() : bool
    {
        return null !== $this->jsonSchema[self::X_CALCULATION];
    }

    /**
     * Read this property from a formula (only for reading, no writing possible!)
     * 
     * @uxon-property x-calculation
     * @uxon-type metamodel:formula
     * @uxon-template =
     * 
     * @see \axenox\ETL\Interfaces\APISchema\APIPropertyInterface::getCalculationExpression()
     */
    public function getCalculationExpression() : ?ExpressionInterface
    {
        if (! $this->isBoundToCalculation()) {
            return null;
        }
        return ExpressionFactory::createFromString($this->workbench, $this->jsonSchema[self::X_CALCULATION], $this->getObjectSchema()->getMetaObject());
    }
    
    /**
     * 
     * @param string $openApiType
     * @param string $format
     * @param array $enumValues
     * @throws \exface\Core\Exceptions\InvalidArgumentException
     * @return BinaryDataType|DataTypeInterface|EnumDataTypeInterface
     */
    protected function findDataType(string $openApiType, string $format = null, array $enumValues = null) : DataTypeInterface
    {
        $workbench = $this->workbench;
        switch ($openApiType) {
            case 'integer':
                return DataTypeFactory::createFromString($workbench, IntegerDataType::class);

            case 'number':
                return DataTypeFactory::createFromString($workbench, NumberDataType::class);

            case 'boolean':
                return DataTypeFactory::createFromString($workbench, BooleanDataType::class);

            case 'array':
                return DataTypeFactory::createFromString($workbench, ArrayDataType::class);

            case 'string':
                if ($format === 'datetime' || $format === 'date') {
                    return DataTypeFactory::createFromString($workbench, $format === 'datetime' ? DateTimeDataType::class : DateTimeDataType::class);
                }
                if ($format === 'byte' || $format === 'binary') {
                    $binaryType = DataTypeFactory::createFromString($workbench, BinaryDataType::class);
                    if ($binaryType instanceof  BinaryDataType) {
                        $binaryType->setEncoding($format === 'byte' ? 'base64' : 'binary');
                    }
                    return $binaryType;
                }
                if ($format === 'datetime') {
                    return DataTypeFactory::createFromString($workbench, DateTimeDataType::class);
                }
                if ($format === 'date') {
                    return DataTypeFactory::createFromString($workbench, DateDataType::class);
                }
                if ($enumValues !== null) {
                    $enumType = DataTypeFactory::createFromString($workbench, StringEnumDataType::class);
                    // In PowerUi we map keys to the values. In this case, the value also needs to be the key, since the OpenApi definition only has one value for the enum.
                    $enumValues = array_combine($enumValues, $enumValues);
                    if ($enumType instanceof EnumDataTypeInterface) {
                        $enumType->setValues($enumValues);
                    }
                    return $enumType;
                }

                return DataTypeFactory::createFromString($workbench, StringDataType::class);

            default:
                throw new InvalidArgumentException('Openapi schema type: ' . $openApiType . ' not recognized.');
        }
    }

    /**
     * 
     * @param string $value
     * @return string
     */
    protected function replacePlaceholders(string $value) : string
    {
        return StringDataType::replacePlaceholders($value, $this->jsonSchema);
    }

    /**
     * 
     * @param string $value
     * @throws \exface\Core\Exceptions\RuntimeException
     * @return array|string
     */
    protected function evaluateFormulas(string $value) : string
    {
        if (Expression::detectFormula($value) === true) {
            $expr = ExpressionFactory::createFromString($this->workbench, $value);
            if ($expr->isStatic()) {
                return $expr->evaluate() ?? '';
            } else {
                throw new RuntimeException('Cannot use dynamic formula "' . $value . '" in an OpenAPI custom attribute!');
            }
        }
        return $value;
    }

    /**
     * 
     * @return bool
     */
    public function isNullable() : bool
    {
        return $this->jsonSchema['nullable'] ?? true;
    }

    /**
     * 
     * @return bool
     */
    public function isRequired() : bool
    {
        return $this->objectSchema->isRequiredProperty($this);
    }
}