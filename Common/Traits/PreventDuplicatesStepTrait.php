<?php
namespace axenox\ETL\Common\Traits;

use axenox\ETL\ETLPrototypes\JsonApiToDataSheet;
use axenox\ETL\Interfaces\DataFlowStepInterface;
use exface\Core\CommonLogic\DataSheets\Matcher\DuplicatesMatcher;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Debug\DataLogBookInterface;
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
    private $updateIfMatchingAttributesViaBehavior = false;
    
    /**
     * 
     * @param MetaObjectInterface $object
     * @param array $compareAttributes
     * @return void
     */
    protected function addDuplicatePreventingBehavior(MetaObjectInterface $object, array $compareAttributes = null) : PreventDuplicatesBehavior
    {
        $compareAttributes = $compareAttributes ?? $this->getUpdateIfMatchingAttributeAliases();
        $behavior = BehaviorFactory::createFromUxon($object, PreventDuplicatesBehavior::class, new UxonObject([
            'compare_attributes' => $compareAttributes,
            'on_duplicate_multi_row' => PreventDuplicatesBehavior::ON_DUPLICATE_UPDATE,
            'on_duplicate_single_row' => PreventDuplicatesBehavior::ON_DUPLICATE_UPDATE
        ]));
        $object->getBehaviors()->add($behavior);
        return $behavior;
    }
    
    protected function getDuplicatesMatcher(DataSheetInterface $toSheet, DataLogBookInterface $logbook) : ?DuplicatesMatcher
    {
        if (! $this->willUpdateViaDuplicatesMatcher()) {
            return null;
        }
        $compareAttributes = $this->getUpdateIfMatchingAttributeAliases();
        if (empty($compareAttributes)) {
            return null;
        }
        $matcher = new DuplicatesMatcher($toSheet, null, $logbook, true);
        $matcher->setCompareAttributes($compareAttributes);
        return $matcher;
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
     * @param UxonObject|array $uxonOrArray
     * @return \axenox\ETL\Interfaces\DataFlowStepInterface
     */
    protected function setUpdateIfMatchingAttributes(UxonObject|array $uxonOrArray) : DataFlowStepInterface
    {
        $this->updateIfMatchingAttributeAliases = $uxonOrArray instanceof UxonObject ? $uxonOrArray->toArray() : $uxonOrArray;
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

    /**
     * Set to TRUE to add a temporary PreventDuplicatesBehavior to the to-object instead of separating CREATEs and UPDATEs inside the step.
     * 
     * @uxon-property update_if_matching_attributes_via_behavior
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $trueOrFalse
     * @return JsonApiToDataSheet
     */
    protected function setUpdateIfMatchingAttributesViaBehavior(bool $trueOrFalse) : JsonApiToDataSheet
    {
        $this->updateIfMatchingAttributesViaBehavior = $trueOrFalse;
        return $this;
    }
    
    protected function willUpdateIfMatchingAttributes() : bool
    {
        return ! empty($this->updateIfMatchingAttributeAliases);
    }

    protected function willUpdateViaPreventDuplicatesBehavior() : bool
    {
        return $this->willUpdateIfMatchingAttributes() && $this->updateIfMatchingAttributesViaBehavior === true;
    }

    protected function willUpdateViaDuplicatesMatcher() : bool
    {
        return $this->willUpdateIfMatchingAttributes() && $this->updateIfMatchingAttributesViaBehavior === false;
    }
}