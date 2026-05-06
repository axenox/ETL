<?php
namespace axenox\ETL\Mutations\MutationPoints;

use axenox\ETL\Events\Flow\OnDataFlowLoaded;
use exface\Core\CommonLogic\Mutations\AbstractMutationPoint;
use exface\Core\Events\Mutations\OnMutationsAppliedEvent;
use exface\Core\Mutations\MetaObjectUidMutationTarget;

class DataFlowMutationPoint extends AbstractMutationPoint
{
    public static function onDataFlowLoadedApplyMutations(OnDataFlowLoaded $event) : void
    {
        $point = $event->getWorkbench()->getMutator()->getMutationPoint(self::class);
        $target = new MetaObjectUidMutationTarget('axenox.ETL.flow', $event->getDataFlow()->getUid());
        $applied = $point->applyMutations($target, $event->getDataFlow());

        if (!empty($applied)) {
            $point->getWorkbench()->eventManager()->dispatch(new OnMutationsAppliedEvent($applied, 'data flow "' . $event->getDataFlow()->getAlias() . '"', $point));
        }
    }
}