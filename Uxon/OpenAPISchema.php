<?php
namespace axenox\ETL\Uxon;

use axenox\ETL\Common\OpenAPI\OpenAPI3;
use axenox\ETL\Common\OpenAPI\OpenAPI3ObjectSchema;
use axenox\ETL\Common\OpenAPI\OpenAPI3Property;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Paths;
use cebe\openapi\spec\Type;
use cebe\openapi\SpecObjectInterface;
use exface\Core\CommonLogic\Uxon\UxonSnippetCall;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\UxonSchemaInterface;
use exface\Core\Uxon\UxonSchema;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use axenox\ETL\Facades\Helper\MetaModelSchemaBuilder;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * UXON-schema class for OpenAPI.json.
 * 
 * @author Andrej Kabachnik
 *
 */
class OpenAPISchema extends UxonSchema
{
    private array $representationMappings = [];
    
    /**
     *
     * @return string
     */
    public static function getSchemaName() : string
    {
        return 'OpenAPI.json';
    }

    public function getMetaObject(UxonObject $uxon, array $path, MetaObjectInterface $rootObject = null) : MetaObjectInterface
    {
        $objectAlias = $this->getPropertyValueRecursive($uxon, $path, 'x-object-alias', ($rootObject !== null ? $rootObject->getAliasWithNamespace() : ''));
        if ($objectAlias !== '' && $objectAlias !== null) {
            return MetaObjectFactory::createFromString($this->getWorkbench(), $objectAlias);
        }
        return parent::getMetaObject($uxon, $path, $rootObject);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\UxonSchemaInterface::getPresets()
     */
    public function getPresets(UxonObject $uxon, array $path, string $rootPrototypeClass = null) : array
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_PRESET');
        $ds->getColumns()->addMultiple(['UID','NAME', 'PROTOTYPE__LABEL', 'DESCRIPTION', 'PROTOTYPE', 'UXON' , 'WRAP_PATH', 'WRAP_FLAG']);
        $ds->getFilters()->addConditionFromString('UXON_SCHEMA', '\\' . __CLASS__, ComparatorDataType::EQUALS);
        $ds->getSorters()
            ->addFromString('PROTOTYPE', SortingDirectionsDataType::ASC)
            ->addFromString('NAME', SortingDirectionsDataType::ASC);
        $ds->dataRead();
        
             
        $objectAlias = empty($path) ? '' : end($path);
        if (($objectAlias ?? '') !== ''){
            $objectSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.OBJECT');
            $objectSheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $objectAlias, ComparatorDataType::IS);
            $objectSheet->getColumns()->addMultiple([
                'NAME',
                'ALIAS',
                'APP__ALIAS',
                'ALIAS_WITH_NS'
            ]);
            $objectSheet->dataRead();
            $schemaBuilder = new MetaModelSchemaBuilder(onlyReturnProperties: true, forceSchema: true, loadExamples: true);
            foreach ($objectSheet->getRows() as $objRow) {
                try {
                    $obj = MetaObjectFactory::createFromString($this->getWorkbench(), $objRow['ALIAS_WITH_NS']);
                    $json = $schemaBuilder->transformIntoJsonSchema($obj);
            
                    $ds->addRow([
                        'UID' => $obj->getId(),
                        'NAME' => $obj->getName() . '<br>Alias: ' . $objRow['ALIAS'] . '<br>App: ' . $objRow['APP__ALIAS'], 
                        'PROTOTYPE__LABEL' => 'Meta objects', 
                        'DESCRIPTION' => '', 
                        'PROTOTYPE' => 'object', 
                        'UXON' => json_encode($json), 
                        'WRAP_PATH' => null, 
                        'WRAP_FLAG' => 0
                    ]);
                } catch (\Throwable $e) {
                    $this->getWorkbench()->getLogger()->logException($e, LoggerInterface::WARNING);
                }
            }
        } else {
            $ds->addRow([
                'UID' => null,
                'NAME' => 'Object presets can only be generated if this dialog is opened for a UXON property holding an object alias',
                'PROTOTYPE__LABEL' => 'Meta objects',
                'DESCRIPTION' => '',
                'PROTOTYPE' => 'object',
                'UXON' => 'T',
                'WRAP_PATH' => null,
                'WRAP_FLAG' => 0
            ]);
        }
        
        return $ds->getRows();
    }

    /**
     * @see UxonSchema::getDefaultPrototypeClass()
     */
    protected function getDefaultPrototypeClass() : string
    { 
        return OpenAPI3::class;
    }

    /**
     * @inheritdoc 
     * @see UxonSchema::loadPropertiesSheet()
     */
    protected function loadPropertiesSheet(string $prototypeClass, string $notationObjectAlias): DataSheetInterface
    {
        $schema = new OpenAPI3($this->getWorkbench(), '{}');

        // Load properties from class annotations.
        $ds = parent::loadPropertiesSheet($prototypeClass, $notationObjectAlias);
        $existingProperties = $ds->getColumnValues('PROPERTY');

        // Load properties from OpenAPI and use mapping, if available.
        $prototypeClass = $this->representationMappings[$prototypeClass] ?? $prototypeClass;
        foreach ($schema->getAttributes($prototypeClass) as $name => $value) {
            if(in_array($name, $existingProperties)) {
                continue;
            }

            $ds->addRow($this->openApiAttributeToUxonProperty($name, $value));
        }

        return $ds;
    }

    /**
     * @inheritdoc 
     * @see UxonSchemaInterface::getPrototypeClass()
     */
    public function getPrototypeClass(UxonObject $uxon, array $path, string $rootPrototypeClass = null): string
    {
        $rootPrototypeClass = $rootPrototypeClass ?? $this->getDefaultPrototypeClass();

        foreach ($uxon as $key => $value) {
            if (strcasecmp($key, UxonObject::PROPERTY_SNIPPET) === 0) {
                $rootPrototypeClass = '\\' . UxonSnippetCall::class;
            }
        }

        if ($rootPrototypeClass === '') {
            return $rootPrototypeClass;
        }

        if (count($path) > 0 && ($uxon->getProperty($path[0]) instanceof UxonObject)) {
            $prop = array_shift($path);

            if (is_numeric($prop) === false) {
                $propType = $this->getPropertyTypes($rootPrototypeClass, $prop)[0];
                if (mb_substr($propType, 0, 1) === '\\') {
                    $class = $propType;
                    $class = str_replace('[]', '', $class);
                    $class = $this->mapToRepresentationClass($prop, $class);
                } else {
                    $class = $rootPrototypeClass;
                }
            } else {
                $class = $rootPrototypeClass;
            }

            $schema = $class === $rootPrototypeClass ? $this : $this->getSchemaForClass($class);
            $propVal = $uxon->getProperty($prop);
            if ($propVal instanceof UxonObject) {
                if (null !== $value = $propVal->getProperty(UxonObject::PROPERTY_SNIPPET)) {
                    $class = '\\' . UxonSnippetCall::class;
                }
            }
            
            return $schema->getPrototypeClass($propVal, $path, $class);
        }

        return $rootPrototypeClass;
    }

    /**
     * Checks if the given class has a representation class and returns the representation class, should it exist. 
     * Otherwise, the original class is returned.
     * 
     * @param string $property
     * @param string $prototypeClass
     * @return string
     */
    protected function mapToRepresentationClass(string $property, string $prototypeClass) : string
    {
        $result = match ($property) {
            'schemas' => OpenAPI3ObjectSchema::class,
            'properties' => OpenAPI3Property::class,
            default => $prototypeClass
        };
        
        if($result !== $prototypeClass) {
            $result = '\\' . $result;
            $this->representationMappings[$result] = $prototypeClass;
        }
        
        return $result;
    }

    /**
     * Transforms an OpenAPI attribute into a row that can be added to a properties sheet.
     * 
     * The resulting array has the following structure:
     * 
     * ```
     *  
     *  [
     *      'PROPERTY' => $name,
     *      'TYPE' => $type,        // Converted to matching DataTypeInterface
     *      'TEMPLATE' => $template // Can be '""', '[""]', '{"":""}', '[{"":""}]' or '{"":{"":""}}'
     *  ]
     * 
     * ```
     * 
     * @param string       $name
     * @param array|string $value
     * @return array
     */
    protected function openApiAttributeToUxonProperty(string $name, array|string $value) : array
    {
        $value = $value === Paths::class ? [Type::STRING, PathItem::class] : $value;

        if(is_array($value)) {
            // OpenAPI uses two kinds of array definitions: [Type] for numeric and [Key, Type] for
            // associative arrays (called "maps" in OpenAPI). In either case, we need the last array element.
            $type = end($value);

            if(count($value) > 1) {
                // Associative
                $template = '{"":*}';
            } else {
                // Numeric
                $template = '[*]';
            }
        } else {
            $template = '*';
            $type = $value;
        }

        $classPath = explode('\\', $type);
        if(count($classPath) > 1) {
            // If the type is a class with namespace, we apply an object template.
            $type = '\\' . $type;
            $template = str_replace('*','{"":""}', $template);
        } else {
            $type = Type::ANY ? Type::STRING : $type;
            $type = get_class(DataTypeFactory::createFromString($this->getWorkbench(), 'exface.core.' . $type));
            // If the type is primitive, but is part of an associative array, we also need the object template.
            // Otherwise, we can use an empty template.
            $template = str_replace('*','""', $template);
        }

        return [
            'PROPERTY' => $name,
            'TYPE' => $type,
            'TEMPLATE' => $template
        ];
    }

    /**
     * @inheritdoc 
     * @see UxonSchema::getSchemaForClass()
     */
    public function getSchemaForClass(string $prototypeClass): UxonSchema
    {
        if(is_a($prototypeClass, SpecObjectInterface::class, true)) {
            return $this;
        }
        
        return parent::getSchemaForClass($prototypeClass);
    }
}