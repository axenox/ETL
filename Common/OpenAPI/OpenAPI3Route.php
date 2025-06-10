<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIRouteInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

class OpenAPI3Route implements APIRouteInterface
{
    use OpenAPI3UxonTrait;
    
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
        $key = $this->getPathToData($schema, $object->getAliasWithNamespace());
        $bodyArray = json_decode($body, true);
        switch (true) {
            case $bodyArray === null:
                $data = null;
                break;
            case $key === null:
                $data = $bodyArray;
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
     * Returns the JSON path to the data in a request body.
     * 
     * TODO currently this is only working for the first level of the request schema.
     *
     * @param array $requestSchema
     * @param string $objectAlias
     * @return string|null
     */
    protected function getPathToData(array $requestSchema, string $objectAlias) : ?string
    {
        $key = null;
        switch (true) {
            case $requestSchema['type'] === 'array':
                $key = $this->getPathToData($requestSchema['items'], $objectAlias);
                break;
            case $requestSchema['type'] === 'object' && $objectAlias === $requestSchema['x-object-alias'] ?? null:
                $key = null;
                break;
            case $requestSchema['type'] === 'object':
                foreach ($requestSchema['properties'] as $propertyName => $propertyValue) {
                    switch (true) {
                        case $objectAlias === $propertyValue['x-object-alias'] ?? null:
                            return $propertyName;
                        case $propertyValue['type'] === 'array':
                        case $propertyValue['type'] === 'object':
                            $key = $this->getPathToData($propertyValue, $objectAlias);
                            break;
                    }
                }
                break;
        }

        return $key;
    }
}