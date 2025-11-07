<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Common\AbstractOpenApiPrototype;
use axenox\ETL\Facades\Helper\MetaModelSchemaBuilder;
use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIRouteInterface;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use axenox\ETL\Uxon\OpenAPISchema;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Facades\AbstractHttpFacade\Middleware\RouteConfigLoader;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use JsonPath\JsonObject;
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
    use OpenAPI3UxonTrait;

    private ?Workbench $workbench;
    private ?array $openAPIJsonArray;
    private ?string $openAPIJson;
    private mixed $openAPIJsonObj;
    private ?OpenApi $openAPISchema;
    private ?string $apiVersion = null;

    public function __construct(WorkbenchInterface $workbench, string $openAPIJson, string $apiVersion = null)
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
        $this->apiVersion = $apiVersion;

        $jsonArray = json_decode($openAPIJson, true);
        $jsonArray = $this->enhanceSchema($jsonArray);
        // Instatiate a cebe/openapi schema and use it to resolve references
        $schema = new OpenApi($jsonArray);
        $schema->resolveReferences(new ReferenceContext($schema, "/"));

        $this->openAPIJsonObj = $schema->getSerializableData();
        $this->openAPIJsonArray = json_decode(json_encode($this->openAPIJsonObj), true);
        $this->openAPISchema = $schema;
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench() : Workbench
    {
        return $this->workbench;
    }

    /**
     * @inheritdoc 
     * @see APISchemaInterface::exportUxonObject()
     */
    public function exportUxonObject() : UxonObject
    {
        return new UxonObject($this->openAPIJsonArray);
    }

    /**
     * @inheritdoc 
     * @see APISchemaInterface::getUxonSchemaClass()
     */
    public static function getUxonSchemaClass() : ?string
    {
        return OpenAPISchema::class;
    }

    /**
     * @see APISchemaInterface::getRouteForRequest()
     */
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

    /**
     * @see APISchemaInterface::getObjectSchema()
     */
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
     * @uxon-type \axenox\ETL\Common\OpenAPI\OpenApi3Component[]
     * 
     * @return mixed
     */
    protected function getComponents() : array
    {
        return $this->openAPIJsonArray['components'];
    }

    /**
     *
     * @return array
     */
    protected function getSchemas() : array
    {
        return $this->openAPIJsonArray['components']['schemas'];
    }
    
    public function __tostring()
    {
        return JsonDataType::encodeJson($this->openAPIJsonObj, true);
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
     * @param array $json
     * @return array
     */
    protected function enhanceSchema(array $json) : array
    {
        $jsonPath = new JsonObject($json);
        
        if ($this->apiVersion !== null) {
             $jsonPath->set('$.info.version', $this->apiVersion);
        }
        
        $examplesToGenerate = $this->extractExamplesGenerators($json);
        
        // Object schemas.
        $schemaPath = '$.components.schemas';
        $examplePath = '$.components.examples';
        foreach ($json['components']['schemas'] as $schemaName => $schema) {
            $objectAlias = $schema['x-object-alias'];
            if(empty($objectAlias)) {
                continue;
            }
            
            $object = MetaObjectFactory::createFromString($this->getWorkbench(), $objectAlias);
            $schema = OpenAPI3ObjectSchema::enhanceSchema($schema, $object);
            $jsonPath->set($schemaPath . '.' . $schemaName, $schema);
            
            foreach ($examplesToGenerate as $exampleName => $exampleSchema) {
                $exampleName = $schemaName . '_' . $exampleName;
                $path = $examplePath . '.' . $exampleName;

                if(!empty($jsonPath->get($path))){
                    continue;
                }

                $exampleJson = $this->generateExampleFromSchema($object, $schema, $exampleSchema);
                $jsonPath->set($examplePath . '.' . $exampleName, $exampleJson);

                // We have to add a reference to the example in "paths", but getting the correct path
                // is not trivial. The Path reference may be named differently than the component it
                // represents. To identify the correct path, we need to match object aliases, which would
                // be difficult with array accessors, which is why we use JSONPath.
                //
                // The path searches for all paths that have a property "content" anywhere in their structure
                // that matches these conditions:
                // - It has a child named "examples". 
                // - It has a child named "schema" with a property "x-attribute-alias".
                // - Said property is equal to the object alias of our $object.
                $schemaFilter = "[?(@.schema.x-object-alias == '{$object->getAliasWithNamespace()}')]";
                $reference = '#/components/examples/' . $exampleName;
                $jsonPath->add(
                    "$.paths..content{$schemaFilter}.examples", 
                    [ '$ref' => $reference ],
                    $exampleName
                );
            }
        }

        return $jsonPath->getValue();
    }

    public function publish(string $baseUrl) : string
    {
        $jsonArray = $this->openAPIJsonArray;
        $jsonArray = $this->removeInternalAttributes($jsonArray);
        $jsonArray = $this->prependLocalServerPaths($baseUrl, $jsonArray);
        return $this->validateSchema(JsonDataType::encodeJson($jsonArray, true));		
    }

    /**
     * Appends all local server paths of the API.
     * @param string $path the actual called path
     * @param array $swaggerArray an array holding information of swagger configuration
     * @return array the modified $swaggerArray
     */
    private function prependLocalServerPaths(string $path, array $swaggerArray): array
    {
        $baseUrls = $this->getWorkbench()->getConfig()->getOption('SERVER.BASE_URLS')->toArray();
        foreach (array_reverse($baseUrls) as $baseUrl) {
            // prepend entry to array
            array_unshift($swaggerArray['servers'], ['url' => $baseUrl . $path]);
        }

        return $swaggerArray;
    }

    /**
     * Removes internal attributes from Swagger API definition.
     * TODO jsc 240917 move to OpenAPI specific Implementation
     * @param array $swaggerArray an array holding information of swagger configuration
     * @return array the modified $swaggerArray
     */
    private function removeInternalAttributes(array $swaggerArray) : array
    {
        $newSwaggerDefinition = [];
        foreach($swaggerArray as $name => $value){
            if ($name === AbstractOpenApiPrototype::OPEN_API_ATTRIBUTE_TO_ATTRIBUTE_CALCULATION
                || $name === AbstractOpenApiPrototype::OPEN_API_ATTRIBUTE_TO_ATTRIBUTE_DATAADDRESS
                || $name === OpenAPI3Property::X_CUSTOM_ATTRIBUTE
                || $name === OpenAPI3Property::X_LOOKUP
                || $name === OpenAPI3Property::X_PROPERTIES_FROM_DATA
                || $name === OpenAPI3Property::X_CALCULATION
            ) {
                continue;
            }
            if (is_array($value)) {
                $newSwaggerDefinition[$name] = $this->removeInternalAttributes($value);
                continue;
            }
            $newSwaggerDefinition[$name] = $value;
        }

        return $newSwaggerDefinition;
    }

    /**
     * 
     * @param string|stdClass|array $json
     * @param \exface\Core\Interfaces\WorkbenchInterface $workbench
     * @return string
     */
    protected function validateSchema($json) : string
    {
        $jsonSchema = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'JSONSchema' . DIRECTORY_SEPARATOR . 'OpenAPI_3.0.json');
        JsonDataType::validateJsonSchema($json, $jsonSchema);
        return $json;
    }    

    /**
     * Selects data from a swaggerJson with the given json path.
     * Route path and method type are used to replace placeholders within the path.
     * 
     * @param string $routePath
     * @param string $methodType
     * @return array|null
     * @throws \Flow\JSONPath\JSONPathException
     */
	public function getResponseDataTemplate(
		string $routePath,
		string $methodType
    ) : ?array
    {
            $jsonPath = '$.paths.[#routePath#].[#methodType#].responses[200].content.application/json.examples.defaultResponse.value';
			$jsonPath = str_replace('[#routePath#]', $routePath, $jsonPath);
			$jsonPath = str_replace('[#methodType#]', $methodType, $jsonPath);
			$data = (new JSONPath($this->openAPIJsonObj))->find($jsonPath)->getData()[0] ?? null;
			return is_object($data) ? get_object_vars($data) : $data;
	}

    /**
     * Extracts example generators from a given OpenAPI JSON and returns an array
     * containing those definitions as well as some basic example generators.
     * 
     * The following properties mark an example as a generator:
     * - `x-attribute-group-alias`
     * - `x-required`
     * 
     * @param array $openApiJson
     * @return array
     */
    protected function extractExamplesGenerators(array &$openApiJson) : array
    {
        $examples = $openApiJson['components']['examples'];

        foreach ($examples as $example => $schema) {
            if(key_exists(OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS, $schema) ||
                key_exists('x-required', $schema)) {
                unset($openApiJson['components']['examples'][$example]);
            } else {
                unset($examples[$example]);
            }
        }

        $examples['Minimum'] = [
            OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS => '~ALL',
            'x-required' => true
        ];

        $examples['Full'] = [
            OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS => '~ALL'
        ];

        return $examples;
    }

    /**
     * @param MetaObjectInterface $object
     * @param array               $objectSchema
     * @param array               $exampleSchema
     * @return array
     */
    protected function generateExampleFromSchema(
        MetaObjectInterface $object, 
        array $objectSchema, 
        array $exampleSchema
    ) : array
    {
        $objectSchema = new OpenAPI3ObjectSchema($this, $objectSchema);
        $requiredFilter = $exampleSchema['x-required'];
        
        $groupFilter = null;
        if(key_exists(OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS, $exampleSchema)) {
            $groupFilter = $object->getAttributeGroup($exampleSchema[OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS]);
        }
        
        $values = [];
        $loadedValues = MetaModelSchemaBuilder::loadExamples($object);
        
        foreach ($objectSchema->getProperties() as $name => $property) {
            $exampleValue = null;
            
            // Filter for property optionality, if the example schema contains such a filter.
            if($requiredFilter !== null &&
                $property->isRequired() !== $requiredFilter
            ) {
                continue;
            }
            
            // Filter for attribute groups, if the example schema contains such a filter.
            if($groupFilter !== null &&
                $property->isBoundToAttribute() &&
                !$property->isBoundToCalculation()
            ) {
                $attribute = $property->getAttribute();
                
                try {
                    $groupFilter->getByAttributeId($attribute->getId());
                } catch (\Throwable) {
                    continue;
                }
                
                $exampleValue = 
                    $loadedValues[$attribute->getAlias()] ?? 
                    $attribute->getDataType()->getValue();
            }

            try {
                $exampleValue = 
                    $property->getExampleValue() ?? 
                    $exampleValue ?? 
                    $property->getPropertyType();
                
                $decoded = json_decode($exampleValue);
                $exampleValue = $decoded ?? $exampleValue;
            } catch (\Throwable) {
                
            }
            
            $values[$name] = $exampleValue;
        }
        
        return ['value' => [$values]]; 
    }
}