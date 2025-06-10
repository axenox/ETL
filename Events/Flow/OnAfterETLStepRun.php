<?php
namespace axenox\ETL\Events\Flow;

/**
 * Event triggered whenever a step run is completed.
 *
 * @event axenox.ETL.Flow.OnAfterETLStepRun
 *
 * @author Andrej Kabachnik
 *
 */
class OnAfterETLStepRun extends OnBeforeETLStepRun
{
    /**
     * {@inheritdoc}
     * @see \exface\Core\Events\AbstractEvent::getEventName()
     */
    public static function getEventName() : string
    {
        return 'axenox.ETL.Flow.OnAfterETLStepRun';
    }
}