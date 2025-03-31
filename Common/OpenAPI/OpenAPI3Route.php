<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIRouteInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

class OpenAPI3Route implements APIRouteInterface
{
    private $openAPISchema = null;
    private $routeSchema = null;

    public function __construct(OpenAPI3 $model, array $routeData)
    {
        $this->openAPISchema = $model;
        $this->routeSchema = $routeData;
    }

    public function getAPI() : APISchemaInterface
    {
        return $this->openAPISchema;
    }

    public function parseData(string $body, MetaObjectInterface $object) : ?array
    {
        $schema = $this->routeSchema;
        $key = $this->getArrayKeyToImportDataFromSchema($schema, $object->getAliasWithNamespace());
        $bodyArray = json_decode($body, true);
        switch (true) {
            case $bodyArray === null:
                $data = null;
                break;
            case is_array($bodyArray):
                // Determine if the request body contains a named array/object or an unnamed array/object
                $data = is_array($bodyArray[$key]) ? $bodyArray[$key] : $bodyArray;
                break;
            default:
                $data = null;
        }
        
        return $data;
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
}