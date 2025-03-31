<?php
namespace axenox\ETL\Common\Traits;

use axenox\ETL\Interfaces\DataFlowStepInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\BehaviorFactory;
use exface\Core\Behaviors\PreventDuplicatesBehavior;

/**
 * 
 * 
 * @author andrej.kabachnik
 *
 */
trait PreventDuplicatesStepTrait
{   
    private $updateIfMatchingAttributeAliases = [];
    
    /**
     * 
     * @param MetaObjectInterface $object
     */
    protected function addDuplicatePreventingBehavior(MetaObjectInterface $object)
    {
        $behavior = BehaviorFactory::createFromUxon($object, PreventDuplicatesBehavior::class, new UxonObject([
            'compare_attributes' => $this->getUpdateIfMatchingAttributeAliases(),
            'on_duplicate_multi_row' => PreventDuplicatesBehavior::ON_DUPLICATE_UPDATE,
            'on_duplicate_single_row' => PreventDuplicatesBehavior::ON_DUPLICATE_UPDATE
        ]));
        $object->getBehaviors()->add($behavior);
        return;
    }
    
    /**
     * 
     * @return string[]
     */
    protected function getUpdateIfMatchingAttributeAliases() : array
    {
        return $this->updateIfMatchingAttributeAliases;
    }
    
    /**
     * The attributes to compare when searching for existing data rows.
     * 
     * If an existing item of the to-object with exact the same values in all of these attributes
     * is found, the step will perform an update and will not create a new item.
     * 
     * **NOTE:** this will overwrite data in all the attributes affected by the `mapper`.
     *
     * @uxon-property update_if_matching_attributes
     * @uxon-type metamodel:attribute[]
     * @uxon-template [""]
     * 
     * @param UxonObject $uxon
     * @return \axenox\ETL\Interfaces\DataFlowStepInterface
     */
    protected function setUpdateIfMatchingAttributes(UxonObject $uxon) : DataFlowStepInterface
    {
        $this->updateIfMatchingAttributeAliases = $uxon->toArray();
        return $this;
    }
    
    /**
     * 
     * @return bool
     */
    protected function isUpdateIfMatchingAttributes() : bool
    {
        return empty($this->updateIfMatchingAttributeAliases) === false;
    }
}