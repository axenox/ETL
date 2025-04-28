<?php
namespace axenox\ETL\Uxon;

use axenox\ETL\Common\OpenAPI\OpenAPI3;
use axenox\ETL\Common\OpenAPI\OpenAPI3ObjectSchema;
use axenox\ETL\Common\OpenAPI\OpenAPI3Property;
use axenox\ETL\Common\OpenAPI\OpenAPI3Route;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Paths;
use cebe\openapi\spec\Schema;
use cebe\openapi\spec\Type;
use cebe\openapi\SpecBaseObject;
use cebe\openapi\SpecObjectInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
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
    /**
     * This array maps APISchema classes to the corresponding OpenAPI classes form cebe/openapi.
     * 
     * This mapping is used for the autosuggest: if an autosuggest for a APISchema prototype is
     * requested, the corresponding OpenAPI attributes will be added automatically. If the prototype
     * is an OpenAPI class, properties of the first matching APISchema class will be added too.
     * 
     * @var string[]
     */
    const CLASS_MAP = [
        '\\' . OpenAPI3::class => '\\' . OpenApi::class,
        // Caution: Object schema and property schema are both JSON schemas
        '\\' . OpenAPI3ObjectSchema::class => '\\' . Schema::class,
        '\\' . OpenAPI3Property::class => '\\' . Schema::class,
        '\\' . OpenAPI3Route::class => '\\' . PathItem::class,
    ];

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
        
        // Create some presets for meta object schemas
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
            $objectSheet->dataRead(50);
            $schemaBuilder = new MetaModelSchemaBuilder(onlyReturnProperties: true, forceSchema: true, loadExamples: true);
            $allAttrRows = [];
            $editbaleAttrRows = [];
            foreach ($objectSheet->getRows() as $objRow) {
                try {
                    $obj = MetaObjectFactory::createFromString($this->getWorkbench(), $objRow['ALIAS_WITH_NS']);
                    $json = $schemaBuilder->transformIntoJsonSchema($obj);
                    $presetName = "<b>{$obj->getName()}</b><br>Alias: {$objRow['ALIAS']}<br>App: {$objRow['APP__ALIAS']}";
                    // Add export template for 
                    $allAttrRows[] = [
                        'UID' => $obj->getId(),
                        'NAME' => $presetName, 
                        'PROTOTYPE__LABEL' => 'Meta objects for export (all attributes)', 
                        'DESCRIPTION' => '', 
                        'PROTOTYPE' => 'object', 
                        'UXON' => json_encode($json), 
                        'WRAP_PATH' => null, 
                        'WRAP_FLAG' => 0
                    ];

                    if (! $obj->isWritable()) {
                        continue;
                    }

                    $editableAttrs = $obj->getAttributes()->getEditable();
                    if (! $editableAttrs->isEmpty()) {
                        foreach ($json['properties'] as $attrAlias => $property) {
                            if (! $editableAttrs->has($attrAlias)) {
                                unset($json['properties'][$attrAlias]);
                                if (is_array($json['required']) && in_array($attrAlias, $json['required'])) {
                                    unset($json['required'][array_search($attrAlias, $json['required'])]);
                                }
                            }
                        }
                        $editbaleAttrRows[] = [
                            'UID' => $obj->getId(),
                            'NAME' => $presetName, 
                            'PROTOTYPE__LABEL' => 'Meta objects for import (editable attributes)', 
                            'DESCRIPTION' => '', 
                            'PROTOTYPE' => 'object', 
                            'UXON' => json_encode($json), 
                            'WRAP_PATH' => null, 
                            'WRAP_FLAG' => 0
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->getWorkbench()->getLogger()->logException($e, LoggerInterface::WARNING);
                }
            }
            foreach ($allAttrRows as $row) {
                $ds->addRow($row);
            }
            foreach ($editbaleAttrRows as $row) {
                $ds->addRow($row);
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
    protected function loadPropertiesSheet(string $prototypeClass, string $aliasOfAnnotationObject = 'exface.Core.UXON_PROPERTY_ANNOTATION'): DataSheetInterface
    {
        // Load properties from class annotations.
        switch (true) {
            // If prototype is a \cebe\openapi\... class, load the corresponding schema class first
            case false !== $schmaClass = array_search($prototypeClass, self::CLASS_MAP, true):
                $ds = parent::loadPropertiesSheet($schmaClass, $aliasOfAnnotationObject);
                break;
            // If it is schema class, that has a cebe-class mapping, remember to load the cebe-class
            case null !== $openApiClass = self::CLASS_MAP[$prototypeClass] ?? null:
                $ds = parent::loadPropertiesSheet($prototypeClass, $aliasOfAnnotationObject);
                break;
            default:
                $ds = parent::loadPropertiesSheet($prototypeClass, $aliasOfAnnotationObject);
        }

        $openApiAttrs = $this->getOpenApiAttributes($openApiClass ?? $prototypeClass);
        $schemaAttrs = $ds->getColumns()->getByExpression('PROPERTY')->getValues();
        foreach ($openApiAttrs as $name => $value) {
            if (! in_array($name, $schemaAttrs)) {
                $ds->addRow($this->openApiAttributeToUxonProperty($name, $value));
            }
        }
        return $ds;
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
            'schema', 'schemas' => OpenAPI3ObjectSchema::class,
            'property', 'properties' => OpenAPI3Property::class,
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

    /**
     * 
     * @param mixed $specBaseObjectClass
     * @return array
     */
    public function getOpenApiAttributes($specBaseObjectClass) : array
    {
        $specBaseObjectClass = $specBaseObjectClass === OpenAPI3::class ? OpenApi::class : $specBaseObjectClass;
        if(!is_a($specBaseObjectClass, SpecBaseObject::class, true)) {
            return [];
        }

        $object = new $specBaseObjectClass([]);
        $functionName = 'attributes';

        // Workaround to access protected method. The alternative is to manually create
        // stubs with uxon-property annotation for each attribute. Both options are brittle,
        // but this approach is easier to maintain.
        return call_user_func(\Closure::bind(
            function () use ($object, $functionName) {
                return $object->{$functionName}();
            },
            null,
            $object
        ));
    }
}