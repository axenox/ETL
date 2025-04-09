<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Facades\Helper\MetaModelSchemaBuilder;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use stdClass;

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
     * @return OpenAPI3Property[]
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
     * If the given instance does not contain a property `x-attribute-group-alias` this
     * function will return FALSE. If it does, it will return an array containing schema
     * conform instances of all attributes belonging to that attribute group.
     * 
     * @param stdClass            $property
     * @param MetaObjectInterface $object
     * @return array|false
     */
    public static function toGroup(stdClass $property, MetaObjectInterface $object) : array|false
    {
        $attributeGroup = $property->{'x-attribute-group-alias'};
        if(empty($attributeGroup)) {
            return false;
        }

        $result = [];
        foreach($object->getAttributeGroup($attributeGroup) as $attribute) {
            if(! $attribute->isWritable()) {
                continue;
            }

            $alias = $attribute->getAlias();
            $property = new stdClass();

            try {
                $propertySchema = MetaModelSchemaBuilder::convertToJsonSchemaDatatype($attribute->getDataType());
            } catch (InvalidArgumentException $e) {
                continue;
            }
            
            foreach ($propertySchema as $prop => $val){
                $property->$prop = $val;
            }
            
            $property->nullable = $attribute->isRequired() !== true;
            $property->description = $attribute->getShortDescription() ?? "";
            $property->{'x-attribute-alias'} = $alias;
            $property->{'x-excel-column'} = $attribute->getName();
            
            $result[$alias] = $property;
        }
        
        return $result;
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
}