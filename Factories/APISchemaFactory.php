<?php

namespace axenox\ETL\Factories;

use axenox\ETL\Common\WebserviceInfo;
use axenox\ETL\Events\OnWebserviceLoaded;
use axenox\ETL\Facades\DataFlowFacade;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\SemanticVersionDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\AbstractStaticFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\WorkbenchInterface;

class APISchemaFactory extends AbstractStaticFactory
{
    /**
     * Loads an API schema and instantiates it.
     *
     * Filters all available schemas based on the info you provided. For example, if you provide
     * a value for `$uid`, only that particular webservice is loaded. You can provide additional
     * filters as needed.
     *
     * @param WorkbenchInterface  $workbench
     * @param string|null         $uid
     * @param string|null         $version
     * @param string|null         $alias
     * @param DataFlowFacade|null $facade
     * @param array|null          $additionalFilters
     * @param bool                $allowDisabledSchemas
     * @return mixed
     */
    public static function loadAPISchema(
        WorkbenchInterface $workbench,
        string $uid = null,
        string $version = null,
        string $alias = null,
        DataFlowFacade $facade = null,
        array $additionalFilters = null,
        bool $allowDisabledSchemas = false
    ) : APISchemaInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.ETL.webservice');
        $ds->getColumns()->addMultiple([
            'UID',
            'name',
            'version',
            'swagger_json',
            'type__schema_class',
            'enabled'
        ]);

        // Configure filters.
        if($uid !== null) {
            $ds->getFilters()->addConditionFromString('UID', $uid);
        }
        if($alias !== null) {
            $ds->getFilters()->addConditionFromString('alias', $alias);
        }
        if($additionalFilters !== null) {
            foreach ($additionalFilters as $filter) {
                $ds->getFilters()->addCondition($filter);
            }
        }

        // Read data.
        $ds->dataRead();
        
        if(!$allowDisabledSchemas && $ds->countRows() > 0) {
            foreach ($ds->getRows() as $idx => $row) {
                if(BooleanDataType::cast($row['enabled']) !== true) {
                    $ds->removeRow($idx, false);
                }
            }
            
            if($ds->countRows() === 0) {
                throw new InvalidArgumentException('All webservices matching these filters `' . $ds->getFilters()->__toString() . '` are DISABLED.');
            }
        }

        // Get result.
        switch ($ds->countRows()) {
            case 0:
                throw new InvalidArgumentException('Cannot find webservice using filters `' . $ds->getFilters()->__toString() . '`.');
            case 1:
                $row = $ds->getRowFirst();
                break;
            default:
                $versionCol = $ds->getColumns()->get('version');
                $bestFit = SemanticVersionDataType::findVersionBest($version ?? '*', $versionCol->getValues());
                
                $idx = $versionCol->findRowByValue($bestFit);
                if($idx !== false) {
                    $row = $ds->getRow($idx);
                } else {
                    $row = $ds->getRowFirst();
                }
                break;
        }
        
        $schemaJson = json_decode($row['swagger_json'], true);
        $webservice = new WebserviceInfo(
            $row['name'],
            $row['version'],
            $row['UID'],
            $schemaJson,
            $facade,
            $row['enabled'] ?? false
        );

        // Dispatch event. This will trigger mutations.
        $workbench->eventManager()->dispatch(new OnWebserviceLoaded($workbench, $webservice));
        
        // Create the schema instance.
        $schemaClass = $row['type__schema_class'];
        $schema = new $schemaClass(
            $workbench,
            json_encode($webservice->getSchemaArray()),
            $webservice->getVersion()
        );
        
        $schema->setWebserviceInfo($webservice);
        return $schema;
    }
}