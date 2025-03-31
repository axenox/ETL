<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIRouteInterface;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Facades\AbstractHttpFacade\Middleware\RouteConfigLoader;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Psr\Http\Message\ServerRequestInterface;
use Flow\JSONPath\JSONPath;

class OpenAPI3 implements APISchemaInterface
{
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

                throw new InvalidArgumentException('From object not found in OpenApi schema!');
        }

        return $fromObjectSchema;
    }

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
}