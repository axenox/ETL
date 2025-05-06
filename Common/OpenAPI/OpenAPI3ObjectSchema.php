<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Facades\Helper\MetaModelSchemaBuilder;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIPropertyInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;

/**
 * Represents an OpenAPI 3.x schema bound to a meta object
 * 
 * Adds the following custom OpenAPI properties:
 * 
 * - `x-object-alias` - the meta object alias
 * 
 * @author Andrej Kabachnik
 */
class OpenAPI3ObjectSchema implements APIObjectSchemaInterface
{
    use OpenAPI3UxonTrait;

    const X_OBJECT_ALIAS = 'x-object-alias';

    private $openAPISchema = null;
    private $jsonSchema = null;
    private $properties = null;
    private $object = null;
    private $updateIfMatchingAttributeAliases = [];

    public function __construct(OpenAPI3 $model, array $jsonSchema)
    {
        $this->openAPISchema = $model;
        $this->jsonSchema = $jsonSchema;
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
            if (null !== $alias = $this->jsonSchema[self::X_OBJECT_ALIAS] ?? null) {
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
     * Converts a given stdClass instance to an attribute group.
     * 
     * If the given instance does not contain a property `X_ATTRIBUTE_GROUP_ALIAS` this
     * function will return FALSE. If it does, it will return an array containing schema
     * conform instances of all attributes belonging to that attribute group.
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
                        if (empty($attrProp['type'] ?? null)) {
                            try {
                                $typeProp = MetaModelSchemaBuilder::convertToJsonSchemaDatatype($attribute->getDataType());
                                $attrProp = array_merge($attrProp, $typeProp);
                            } catch (InvalidArgumentException $e) {
                                $object->getWorkbench()->getLogger()->logException($e);
                            }
                        }
                        
                        $attrProp['nullable'] = $attribute->isRequired() !== true;
                        $attrProp['description'] = $attribute->getShortDescription() ?? "";
                        $attrProp['x-attribute-alias'] = $alias;
                        $attrProp['x-excel-column'] = $attribute->getName();

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
                    
                    foreach ($propNameCol->getValues() as $dataPropName) {
                        $schema['properties'][$dataPropName] = $property;
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
        return in_array($property->getPropertyName(), $this->jsonSchema['required']);
    }
    
    /**
     * 
     * @return string[]
     */
    public function getUpdateIfMatchingAttributeAliases() : array
    {
        return $this->jsonSchema['x-update-if-matching-attributes'] ?? [];
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
        return empty($this->jsonSchema['x-update-if-matching-attributes'] ?? []) === false;
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
        $result = JsonDataType::validateJsonSchema($rowObj, $this->jsonSchema);
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