<?php

namespace axenox\ETL\Mutations\MutationPoints;

use axenox\ETL\Events\OnWebserviceLoaded;
use exface\Core\CommonLogic\Mutations\AbstractMutationPoint;
use exface\Core\Events\Mutations\OnMutationsAppliedEvent;
use exface\Core\Mutations\MetaObjectUidMutationTarget;

class WebserviceMutationPoint extends AbstractMutationPoint
{
    public static function onWebserviceLoadedApplyMutations(OnWebserviceLoaded $event) : void
    {
        $point = $event->getWorkbench()->getMutator()->getMutationPoint(self::class);
        
        $webservice = $event->getWebservice();
        $uid = $webservice->getWebserviceInfo()->getUid();
        $name = $webservice->getWebserviceInfo()->getName() ?? 'Unknown Webservice';
        if($uid === null) {
            return;
        }
        
        $target = new MetaObjectUidMutationTarget('axenox.ETL.webservice', $uid);

        $applied = $point->applyMutations($target, $event->getApiSchema());
        if (!empty($applied)) {
            $point->getWorkbench()->eventManager()->dispatch(new OnMutationsAppliedEvent($applied, 'Webservice "' . $name . '"', $point));
        }
    }
}