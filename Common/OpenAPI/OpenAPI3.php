<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Common\AbstractOpenApiPrototype;
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
use exface\Core\Exceptions\Model\MetaModelLoadingFailedError;
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
 * ## Writing Examples
 * 
 * Power-UI automagically generates two API examples for every object schema: "Minimum" (only required properties) and
 * "Full" (all properties). You can expand upon these, by either writing additional manual examples or by
 * adding more example generators. 
 * 
 * ### Manual Examples
 * 
 * To create a new example, you need to add a new property under `components > examples`. Its name will be displayed as 
 * the title of your example, make it descriptive. Manual examples must have the following structure:
 * 
 * ```
 *  
 *  "examples" : [
 *      "Your_Example": {
 *          "value": [
 *              {
 *                  "PropertyName": "Value",
 *                  "PropertyName": "Value",
 *                  ...
 *              }
 *          ]
 *      }
 *  ]
 *  
 * ```
 * 
 * This example will then be available for any user as a template. Use the property names as they are displayed in 
 * your schema and use meaningful example values to help users understand how to format their data. Finally, you need
 * to add a reference to your example to each `paths` endpoint you want to apply it to. Navigate to 
 * `paths > /YourPath > post > requestBody > content > application/json > examples` and add 
 * "#/components/examples/Your_Example".
 * 
 * ### Example Generators
 * 
 * Writing manual examples is a lot of hard work and there is a high risk of producing typos or forgetting
 * properties. And whenever the schema needs to be updated, you also have to update all of its examples.
 * Generators solve all these issues, by allowing you to automatically generate examples for a well-defined
 * subset of all properties in a given schema.
 * 
 * To create a generator, simply add a new entry under `components > examples` and give it a descriptive name.
 * Any example that has either of these properties, will be treated as a generator:
 * 
 * - `x-attribute-group-alias`: Filters all properties, based on which attribute groups they belong to. Properties
 * that are not bound to an attribute will always be included.
 * - `x-required-for-api`: Filters all properties, based on whether they have been declared as required by your 
 * API-Definition. This filter does not check the underlying attributes.
 * 
 * For example, a generator that only shows required properties with their attributes visible would look like this 
 * (note that you don't need to add the "value" property):
 * 
 * ```
 * 
 *  "examples": [
 *      "Your_Generator":{
 *          "x-attribute-group-alias": "~VISIBLE",
 *          "x-required-for-api": true
 *      }
 *  ]
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 */
class OpenAPI3 implements APISchemaInterface
{
    public const X_REQUIRED_FOR_API = 'x-required-for-api';
    private const CFG_EXAMPLE_REQUIRED = 'API_EXAMPLES.REQUIRED';
    private const CFG_EXAMPLE_FULL = 'API_EXAMPLES.FULL';
    
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

            try {
                foreach ($examplesToGenerate as $exampleName => $exampleSchema) {
                    $exampleName = $schemaName . '_' . $exampleName;
                    $path = $examplePath . '.' . $exampleName;

                    if(!empty($jsonPath->get($path))){
                        continue;
                    }

                    $exampleJson = $this->generateExampleFromSchema($object, $schema, $exampleSchema);
                    
                    if(empty($jsonPath->get($examplePath))) {
                        $jsonPath->add('$.components', [], 'examples');
                    }
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
                    $referencePath = "$.paths..content{$schemaFilter}";

                    // Ensure folder.
                    if(empty($jsonPath->get($referencePath . ".examples"))) {
                        $jsonPath->add($referencePath, [], 'examples');
                    }

                    // Add reference.
                    $reference = '#/components/examples/' . $exampleName;
                    $jsonPath->add(
                        $referencePath . ".examples",
                        [ '$ref' => $reference ],
                        $exampleName
                    );
                }
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException(new MetaModelLoadingFailedError(
                    'Failed to generate examples for API definition!', '83K3MPU', $e
                ));
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
     * - `x-required-for-api`
     * 
     * @param array $openApiJson
     * @return array
     */
    protected function extractExamplesGenerators(array &$openApiJson) : array
    {
        $examples = $openApiJson['components']['examples'];

        foreach ($examples as $example => $schema) {
            if(key_exists(OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS, $schema) ||
                key_exists(self::X_REQUIRED_FOR_API, $schema)) {
                unset($openApiJson['components']['examples'][$example]);
            } else {
                unset($examples[$example]);
            }
        }

        $cfg = $this->workbench->getApp('axenox.ETL')->getConfig();

        $exampleKey = $cfg->hasOption(self::CFG_EXAMPLE_REQUIRED) ?
            $cfg->getOption(self::CFG_EXAMPLE_REQUIRED) : 
            'Required';
        
        $examples[$exampleKey] = [
            OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS => '~ALL',
            self::X_REQUIRED_FOR_API => true
        ];

        $exampleKey = $cfg->hasOption(self::CFG_EXAMPLE_FULL) ?
            $cfg->getOption(self::CFG_EXAMPLE_FULL) : 
            'Full';

        $examples[$exampleKey] = [
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
        $requiredFilter = $exampleSchema[self::X_REQUIRED_FOR_API];
        
        $groupFilter = null;
        $customAttributes = $object->getAttributeGroup('~CUSTOM');
        if(key_exists(OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS, $exampleSchema)) {
            $groupFilter = $object->getAttributeGroup($exampleSchema[OpenAPI3Property::X_ATTRIBUTE_GROUP_ALIAS]);
        }
        
        $values = [];
        $attributesToLoad = [];
        
        foreach ($objectSchema->getProperties() as $property) {
            if(!$property->isBoundToAttribute() || $property->isBoundToCalculation()) {
                continue;
            }
            
            $attribute = $property->getAttribute();

            // Filter for attribute groups, if the example schema contains such a filter.
            if($groupFilter !== null) {
                try {
                    $groupFilter->getByAttributeId($attribute->getId());
                } catch (\Throwable) {
                    continue;
                }
            }

            $attributesToLoad[] = $attribute;
        }
        
        $loadedValues = OpenAPI3MetaModelSchemaBuilder::loadExamples($object, $attributesToLoad);
        
        foreach ($objectSchema->getProperties() as $name => $property) {
            $exampleValue = null;
            $attribute = null;
            
            if($property->isBoundToAttribute()) {
                $attribute = $property->getAttribute();
                $exampleValue = 
                    $loadedValues[$attribute->getAlias()] ?? 
                    $attribute->getDataType()->getValue();
            }

            // Filter for property optionality, if the example schema contains such a filter.
            if($requiredFilter !== null) {
                try {
                    $customAttributes->getByAttributeId($attribute->getId());
                    $isCustom = true;
                } catch (\Throwable) {
                    $isCustom = false;
                }

                $isRequired = $isCustom ? $attribute->isRequired() : $property->isRequired();
                
                if($isRequired !== $requiredFilter) {
                    continue;
                }
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