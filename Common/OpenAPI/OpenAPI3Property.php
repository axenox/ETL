<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIPropertyInterface;
use exface\Core\CommonLogic\Model\CustomAttribute;
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
 * ## Property annotations
 * 
 * Each schema property the following cusotm OpenAPI properties:
 * 
 * - `x-attribute-alias` - the meta model attribute this property is bound to
 * - `x-lookup` - the Uxon object to look up for this property
 * - `x-calculation` - the calculation expression for this property
 * - `x-custom-attribute` - create a custom attribute for the object right here (for export-steps only)
 * 
 * ## Template annotations
 * 
 * Some special annotations make a schma property be treated as a template. In this case, it will be
 * replaced with multiple auto-generated properties when the OpenAPI.json is rendered.
 * 
 * - `x-attribute-group-alias` - replaces this property with properties generated from each attribute of
 * the group. If the property has any other options like `type`, `description`, etc. they will be used
 * as a template and remain visible unless the generated properties overwrite them. Template properties
 * support placeholders for attribute UXON properties like `[#alias#]` and formulas like `=Lookup()` or
 * even formulas with placeholders inside!
 * - `x-properties-from-data` replace this property with properties generated from a data sheet. In contrast
 * to `x-attribute-group-alias`, this properties may not even be bound to meta attributes - they can be
 * generated absolutely freely. Other options of the property may use placeholders, that will be filled with
 * values from the data sheet.
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
     * @uxon-template {"lookup_object_alias":"// Look in this object","lookup_column":"// Take this value from the lookup-object and put it into the property attribute", "if_not_found": "leave_empty","matches":[{"from":"// OpenAPI property","lookup":"// column in the lookup data"}]}
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
     * For an API importing or exporting `ORDER` objects we can quickly publish all custom attributes
     * of orders with a single template - just add a property with `x-attribute-group-alias` and you are
     * done.
     * 
     * However, if we also need an x-excel-column for every generated property, we need some more configuration
     * here. In the simplest case, we could use `"x-excel-column": "[#name#]"`, which would expect excel columns
     * to be named after the attributes. 
     * 
     * Or we can even use a `=Lookup()` formula to take the excel column names from a special column in the definition 
     * of the attributes. Assume, our `ORDER` has a `CustomAttributesJsonBehavior` and the attribute definitions are 
     * stored in `my.App.ORDER_ATTRIBUTE`.
     * 
     * ```
     * {
     *  "properties": {
     *      "CustomAttributes": {
     *          "x-attribute-group-alias": "~CUSTOM",
     *          "x-excel-column": "=Lookup('ExcelColumn', 'my.App.ORDER_ATTRIBUTE', 'ALIAS == [#alias#]')"
     *      }
     *  }
     * }
     * 
     * ```
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
     * Returns TRUE if this property is a template for properties generated from a data sheet
     * 
     * @return bool
     */
    public function isBoundToData() : bool
    {
        return null !== $this->getDataSheetToLoadProperties();
    }

    /**
     * Generate multiple properties from data (e.g. master data).
     * 
     * For example, to generate properties for every available event type:
     * 
     * ```
     * {
     *  "properties": {
     *      "EventTypes": {
     *          "type": "date",
     *          "description": "[#Description#]",
     *          "example": "28.06.2025",
     *          "x-excel-column": "[#ExcelColumn#]",
     *          "x-properties-from-data": {
     *              "object_alias": "nbr.OneLink.Termintyp",
     *              "columns": [
     *                  {"attribute_alias": "Name"},
     *                  {"attribute_alias": "ExcelColumn"},
     *                  {"attribute_alias": "Description"}
     *              ]
     *          }
     *      }
     * }
     * 
     * ```
     * 
     * The property `EventTypes` will be replaced by as many properties as there are event types.
     * 
     * **IMPORTANT**: The name of each property will be taken from the first column of the
     * data sheet!
     * 
     * Other options of the property will be simply inherited from the template. Placeholders can be
     * use to include any additional data. Any columns from the defined data sheet can be used as placeholders.
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