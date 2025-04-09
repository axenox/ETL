<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIRouteInterface;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Uxon\OpenAPISchema;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Facades\AbstractHttpFacade\Middleware\RouteConfigLoader;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Psr\Http\Message\ServerRequestInterface;
use Flow\JSONPath\JSONPath;
use stdClass;

/**
 * API schema for OpenAPI 3.0 allowing additional `x-` attributes to bind to the meta model
 * 
 * @author Andrej Kabachnik
 */
class OpenAPI3 implements APISchemaInterface
{
    use ImportUxonObjectTrait;

    private $workbench = null;
    private $openAPIJson = null;
    private $openAPIJsonObj = null;
    private $openAPIJsonArray = null;
    private $openAPISchema = null;

    public function __construct(WorkbenchInterface $workbench, string $openAPIJson)
    {
        // Use local version of JSONPathLexer with edit to
        // Make sure to require BEFORE the JSONPath classes are loaded, so that the custom lexer replaces
        // the one shipped with the library.
        require_once '..' . DIRECTORY_SEPARATOR
            . '..' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR
            . 'etl' . DIRECTORY_SEPARATOR
            . 'Common' . DIRECTORY_SEPARATOR
            . 'JSONPath' . DIRECTORY_SEPARATOR
            . 'JSONPathLexer.php';
            
        $this->workbench = $workbench;
        $this->openAPIJson = $openAPIJson;
        
        $schema = new OpenApi(json_decode($openAPIJson, true));
        $schema->resolveReferences(new ReferenceContext($schema, "/"));
        
        $this->openAPIJsonObj = $schema->getSerializableData();
        $this->openAPIJsonArray = json_decode(json_encode($this->openAPIJsonObj), true);

        $this->openAPISchema = $schema;
    }

    public function getRouteForRequest(ServerRequestInterface $request) : APIRouteInterface
    {
        $jsonPath = '$.paths.[#routePath#].[#methodType#].requestBody.content.[#ContentType#].schema';
        $routePath = RouteConfigLoader::getRoutePath($request);
        $methodType = strtolower($request->getMethod());
        $contentType = $request->getHeader('Content-Type')[0];
        $jsonPath = str_replace(
            ['[#routePath#]', '[#methodType#]', '[#ContentType#]'],
            [$routePath, $methodType, $contentType],
            $jsonPath);
        $jsonPathFinder = new JSONPath($this->openAPIJsonArray);
        $data = $jsonPathFinder->find($jsonPath)->getData()[0] ?? null;

        if ($data === null) {
            throw new InvalidArgumentException('Cannot find necessary request schema in OpenApi. Please check the OpenApi definition!´.'
                . $jsonPath
                . '´ Please check the OpenApi definition!'
            );
        }

        return new OpenAPI3Route($this, (array) $data);
    }

    public function getObjectSchema(MetaObjectInterface $object, string $customSchemaName = null) : APIObjectSchemaInterface
    {
        $schemas = $this->getSchemas();

        if ($customSchemaName !== null && array_key_exists($customSchemaName, $schemas)) {
            $schemaArray = $schemas[$customSchemaName];
        } else {
            $schemaArray = $this->findObjectSchema($object);
        }
        if ($schemaArray[OpenAPI3ObjectSchema::X_OBJECT_ALIAS] !== $object->getAliasWithNamespace()) {
            throw new InvalidArgumentException('From sheet does not match ' .
                OpenAPI3ObjectSchema::X_OBJECT_ALIAS .
                ' of found schema in the OpenApi definition!'
            );
        }
        return new OpenAPI3ObjectSchema($this, $schemaArray);
    }

    protected function findObjectSchema(MetaObjectInterface $object): array
    {
        $schemas = $this->getSchemas();
        switch(true) {
            case array_key_exists($object->getAliasWithNamespace(), $schemas):
                $fromObjectSchema = $schemas[$object->getAliasWithNamespace()];
                break;
            default:
                foreach ($schemas as $schema) {
                    if ($schema[OpenAPI3ObjectSchema::X_OBJECT_ALIAS] === $object->getAliasWithNamespace()) {
                        return $schema;
                    }
                }

                throw new InvalidArgumentException('Object ' . $object->__toString() . ' not found in OpenApi schema `x-object-alias` properties!');
        }

        return $fromObjectSchema;
    }

    /**
     * @uxon-property components
     * @uxon-type \axenox\ETL\Common\OpenAPI\OpenAPI3ObjectSchema[]
     * 
     * @return mixed
     */
    protected function getSchemas() : array
    {
        return $this->openAPIJsonArray['components']['schemas'];
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->workbench;
    }

    public function exportUxonObject()
    {
        return new UxonObject($this->openAPIJsonArray);
    }

    public static function getUxonSchemaClass() : ?string
    {
        return OpenAPISchema::class;
    }    

    /**
     * Injects custom attributes into a given JSON string.
     * 
     * Custom attributes are fetched based on `x-object-alias` properties detected within
     * `'json' => 'components' => 'schemas'`. Custom attributes are filtered based on their 
     * source, discarding all attributes that were not generated by `CustomAttributesJsonBehavior`. 
     * 
     * TODO geb 2025-03-11: We could make this filter configurable, but only if needed.
     * 
     * @param string $json
     * @return stdClass
     */
    public static function enhanceSchema(string $json, WorkbenchInterface $workbench) : stdClass
    {
        $json = json_decode($json);
        $schemas = $json->components->schemas;
        
        foreach ($schemas as $schema) {
            $objectAlias = $schema->{'x-object-alias'};
            if(empty($objectAlias)) {
                continue;
            }
            
            $object = MetaObjectFactory::createFromString($workbench, $objectAlias);
            $properties = $schema->properties;
            foreach ($properties as $propertyName => $property) {
                $result = OpenAPI3ObjectSchema::toGroup($property, $object);
                if($result === false) {
                    continue;
                }
                
                foreach ($result as $key => $value) {
                    if(!empty($properties->{$key})) {
                        continue;
                    }
                    
                    $properties->{$key} = $value;
                }
                
                unset($properties->{$propertyName});
            }
        }
        
        return $json;
    }
}