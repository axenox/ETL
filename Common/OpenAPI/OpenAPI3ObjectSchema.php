<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;

class OpenAPI3ObjectSchema implements APIObjectSchemaInterface
{
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

    public function getProperties() : array
    {
        if ($this->properties === null) {
            foreach ($this->jsonSchema['properties'] as $propName => $propSchema) {
                $this->properties[$propName] = new OpenAPI3Property($this, $propSchema);
            }
        }
        return $this->properties;
    }

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
}