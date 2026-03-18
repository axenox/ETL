<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIPropertyInterface;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\FormulaFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use JsonPath\JsonObject;

/**
 * Represents an OpenAPI 3.x schema bound to a meta object
 * 
 * Adds the following custom OpenAPI properties:
 * 
 * - `x-object-alias` - namespaced alias of the object represented by this schema
 * - `x-update-if-matching-attributes` - array of attribute aliases to use to determine if the import row
 * is a create or an update.
 * 
 * @author Andrej Kabachnik
 */
class OpenAPI3ObjectSchema implements APIObjectSchemaInterface
{
    use OpenAPI3UxonTrait;

    const X_OBJECT_ALIAS = 'x-object-alias';
    const X_UPDATE_IF_MATCHING_ATTRIBUTES = 'x-update-if-matching-attributes';
    const X_OBJECT_UID = 'x-object-uid';
    const X_OBJECT_LABEL = 'x-object-label';

    private $openAPISchema = null;
    private $jsonSchema = null;
    private $jsonValidationSchema = null;
    private $properties = null;
    private $object = null;
    private $updateIfMatchingAttributeAliases = [];

    public function __construct(OpenAPI3 $model, array $jsonSchema)
    {
        $this->openAPISchema = $model;
        $this->jsonSchema = $jsonSchema;

        // Validation expects "nullable" properties to be represented by "type":["actualType","null"], which is not 
        // intuitive for designers, so we append the "null" here.
        try {
            $jsonObject = new JsonObject($jsonSchema);

            foreach($jsonObject->getJsonObjects('$..properties[?(@.nullable == true)]') as $nullableProperty) {
                $type = $nullableProperty->get('$.type');

                // Don't perform any work if the type is already nullable OR if the type wasn't specified.
                if(empty($type) || in_array('null', $type)) {
                    continue;
                }

                $value = ['null'];
                foreach ($type as $typeValue) {
                    $value[] = $typeValue;
                }
                
                $nullableProperty->set('$.type', $value);
            }

            $this->jsonValidationSchema = $jsonObject->getValue();
        } catch (\Throwable $exception) {
            $this->jsonValidationSchema = $jsonSchema;
        }
    }

    public function getAPI() : APISchemaInterface
    {
        return $this->openAPISchema;
    }

    public function getPropertyNames() : array
    {
        return array_keys($this->getProperties());
    }

    /**
     * Properties of this data object
     * 
     * @uxon-property properties
     * @uxon-type \axenox\ETL\Common\OpenAPI\OpenAPI3Property[]
     * 
     * @return array<string, OpenAPI3Property>
     */
    public function getProperties() : array
    {
        if ($this->properties === null) {
            foreach ($this->jsonSchema['properties'] as $propName => $propSchema) {
                $this->properties[$propName] = new OpenAPI3Property($this, $propName, $propSchema);
            }
        }
        return $this->properties;
    }

    public function getProperty(string $name) : ?APIPropertyInterface
    {
        return $this->getProperties()[$name] ?? null;
    }

    /**
     * The meta model object represented by this schema
     * 
     * @uxon-property x-object-alias
     * @uxon-type metamodel:object
     * 
     * @return MetaObjectInterface
     */
    public function getMetaObject() : ?MetaObjectInterface
    {
        if ($this->object === null) {
            if (null !== $alias = ($this->jsonSchema[self::X_OBJECT_ALIAS] ?? null)) {
                $this->object = MetaObjectFactory::createFromString($this->getAPI()->getWorkbench(), $alias);
            }
        }
        return $this->object;
    }

    public function getFormatOption(string $format, string $option) : mixed 
    {
        return $this->jsonSchema['x-' . $format . '-' . $option] ?? null;
    }

    public function getJsonSchema() : array
    {
        return $this->jsonSchema;
    }    

    /**
     * Renders templates for attribute groups or data-driven properties in the given schema
     * 
     * @param array $property
     * @param MetaObjectInterface $object
     * @return array
     */
    public static function enhanceSchema(array $schema, MetaObjectInterface $object) : array
    {
        $properties = $schema['properties'];
        foreach ($properties as $propertyName => $property) {
            switch (true) {
                // x-attribute-group-alias
                case array_key_exists(OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS, $property):
                    $groupAlias = $property[OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS];
                    if(empty($groupAlias)) {
                        unset ($schema['properties'][$propertyName]);
                        break;
                    }

                    foreach($object->getAttributeGroup($groupAlias) as $attribute) {
                        if(! $attribute->isWritable()) {
                            continue;
                        }
                        $alias = $attribute->getAlias();
                        $attrProp = $property;

                        // Handle placeholders
                        $attrPropTpl = JsonDataType::encodeJson($attrProp);
                        $phs = StringDataType::findPlaceholders($attrPropTpl);
                        if (! empty ($phs)) {
                            $attrPhs = $attribute->exportUxonObject()->toArray();
                            $attrPropTpl = StringDataType::replacePlaceholders($attrPropTpl, $attrPhs);
                            $attrProp = JsonDataType::decodeJson($attrPropTpl);
                        }
                        // Evaluate formulas if the value of a property option is a formula
                        foreach ($attrProp as $key => $val) {
                            if (Expression::detectFormula($val)) {
                                $formula = FormulaFactory::createFromString($object->getWorkbench(), $val);
                                $attrProp[$key] = $formula->evaluate();
                            }
                        }

                        // Determine data type
                        if (empty($attrProp['type'] ?? null)) {
                            try {
                                $typeProp = JsonDataType::convertDataTypeToJsonSchemaType($attribute->getDataType());
                                $attrProp = array_merge($attrProp, $typeProp);
                            } catch (InvalidArgumentException $e) {
                                $object->getWorkbench()->getLogger()->logException($e);
                            }
                        }
                        
                        $attrProp['nullable'] = $attribute->isRequired() !== true;
                        $attrProp['description'] = $attribute->getShortDescription() ?? "";
                        $attrProp['x-attribute-alias'] = $alias;

                        $schema['properties'][$alias] = $attrProp;
                    }
                    unset ($schema['properties'][$propertyName]);
                    break;
                
                // x-properties-from-data
                case array_key_exists(OpenAPI3Property::X_PROPERTIES_FROM_DATA, $property):
                    $array = $property[OpenAPI3Property::X_PROPERTIES_FROM_DATA];
                    $dataSheet = DataSheetFactory::createFromUxon($object->getWorkbench(), UxonObject::fromAnything($array));
                    $propNameCol = $dataSheet->getColumns()->getFirst();
                    $dataSheet->dataRead();
                    
                    $tpl = JsonDataType::encodeJson($property);
                    $phs = StringDataType::findPlaceholders($tpl);
                    
                    foreach ($dataSheet->getRows() as $row) {
                        $phVals = [];
                        foreach ($phs as $ph) {
                            $phVals[$ph] = $row[$ph];
                        }
                        $json = StringDataType::replacePlaceholders($tpl, $phVals);
                        $schema['properties'][$row[$propNameCol->getName()]] = JsonDataType::decodeJson($json);
                    }
                    
                    unset ($schema['properties'][$propertyName]);
                    break;
            }
        }
        
        return $schema;
    }

    /**
     * 
     * @param \axenox\ETL\Common\OpenAPI\OpenAPI3Property $property
     * @return bool
     */
    public function isRequiredProperty(OpenAPI3Property $property) : bool
    {
        return in_array($property->getPropertyName(), $this->jsonSchema['required'] ?? []);
    }
    
    /**
     * 
     * @return string[]
     */
    public function getUpdateIfMatchingAttributeAliases() : array
    {
        return $this->jsonSchema[self::X_UPDATE_IF_MATCHING_ATTRIBUTES] ?? [];
    }
    
    /**
     * The attributes to compare when searching for existing data rows.
     * 
     * If an existing item of the to-object with exact the same values in all of these attributes
     * is found, the step will perform an update and will not create a new item.
     * 
     * **NOTE:** this will overwrite data in all the attributes affected by the `mapper`.
     * 
     * @uxon-property x-update-if-matching-attributes
     * @uxon-type metamodel:attribute[]
     * @uxon-template [""]
     * 
     * @return bool
     */
    public function isUpdateIfMatchingAttributes() : bool
    {
        return empty($this->jsonSchema[self::X_UPDATE_IF_MATCHING_ATTRIBUTES] ?? []) === false;
    }

    /**
     * {@inheritDoc}
     * @see APIObjectSchemaInterface::getUidProperties()
     */
    public function getUidProperties() : null|array
    {
        $props = $this->jsonSchema[self::X_OBJECT_UID];
        return is_array($props) ? $props : [$props];
    }

    /**
     * One or more property names, that form the UID of one instance of this object.
     * 
     * @uxon-property x-object-uid
     * @uxon-type array|string
     * @uxon-template [""]
     * 
     * @see APIObjectSchemaInterface::hasUidProperties()
     */
    public function hasUidProperties() : bool
    {
        return '' !== ($this->jsonSchema[self::X_OBJECT_UID] ?? '');
    }

    /**
     * {@inheritDoc}
     * @see APIObjectSchemaInterface::getLabelPropertyName()
     */
    public function getLabelPropertyName() : ?string
    {
        return $this->jsonSchema[self::X_OBJECT_LABEL];
    }

    /**
     * Name of the property, that form the UID of one instance of this object.
     * 
     * @uxon-property x-object-label
     * @uxon-type string
     * 
     * @see APIObjectSchemaInterface::hasLabelProperty()
     */
    public function hasLabelProperty() : bool
    {
        return '' !== ($this->jsonSchema[self::X_OBJECT_LABEL] ?? '');
    }

    /**
     * 
     * @param array $arrayOfRows
     * @return array
     */
    public function validateRows(array $arrayOfRows) : array
    {
        foreach ($arrayOfRows as $row) {
            $this->validateRow($row);
        }
        return $arrayOfRows;
    }

    /**
     * 
     * @param array $properties
     * @return array
     */
    public function validateRow(array $properties) : array
    {
        $rowObj = json_decode(json_encode($properties));
        $result = JsonDataType::validateJsonSchema($rowObj, $this->jsonValidationSchema);
        return $properties;
    }

    /**
     * @uxon-property additionalProperties
     * @uxon-type \axenox\ETL\Common\OpenAPI\OpenAPI3Property
     * @uxon-template true
     * 
     * @return OpenAPI3Property|null
     */
    public function getAdditionalProperties() : ?OpenAPI3Property
    {
        return $this->jsonSchema['additionalProperties'];
    }
}