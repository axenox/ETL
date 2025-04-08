<?php
namespace axenox\ETL\Uxon;

use axenox\ETL\Common\OpenAPI\OpenAPI3;
use cebe\openapi\SpecBaseObject;
use exface\Core\CommonLogic\UxonObject;
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

    protected function getDefaultPrototypeClass() : string
    { 
        return OpenAPI3::class;
    }

    protected function loadPropertiesSheet(string $prototypeClass, string $notationObjectAlias): DataSheetInterface
    {
        $ds = parent::loadPropertiesSheet($prototypeClass, $notationObjectAlias);

        $existingProperties = $ds->getColumnValues('PROPERTY');
        $schema = new OpenAPI3($this->getWorkbench(), '{}');
        
        foreach ($schema->getAttributes($prototypeClass) as $attr => $type) {
            if(in_array($attr, $existingProperties)) {
                continue;
            }
            
            $ds->addRow([
                'PROPERTY' => $attr,
                'TYPE' => '\\' . (is_array($type) ? $type[0] : $type),
                'TEMPLATE' => is_array($type) ? '[{"":""}]' : '{"":""}'
            ]);
        }
        
        return $ds;
    }

    public function getSchemaForClass(string $prototypeClass): UxonSchema
    {
        if(is_a($prototypeClass, SpecBaseObject::class, true)) {
            return $this;
        }
        
        return parent::getSchemaForClass($prototypeClass); // TODO: Change the autogenerated stub
    }


}