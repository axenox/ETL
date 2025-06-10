<?php
namespace axenox\ETL\Events\Flow;

use exface\Core\Events\AbstractEvent;
use axenox\ETL\Interfaces\ETLStepInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;

/**
 * Event triggered whenever a step run is about to be started.
 *
 * @event axenox.ETL.Flow.OnBeforeETLStepRun
 *
 * @author Andrej Kabachnik
 *
 */
class OnBeforeETLStepRun extends AbstractEvent
{
    private ?ETLStepInterface $step = null;
    
    private ?LogBookInterface $logBook = null;

    /**
     * @param ETLStepInterface      $step
     * @param LogBookInterface|null $logBook
     */
    public function __construct(ETLStepInterface $step, LogBookInterface $logBook = null)
    {
        $this->step = $step;
        $this->logBook = $logBook;
    }
    
    /**
     * {@inheritdoc}
     * @see \exface\Core\Events\AbstractEvent::getEventName()
     */
    public static function getEventName() : string
    {
        return 'axenox.ETL.Flow.OnBeforeETLStepRun';
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->step->getWorkbench();
    }
    
    /**
     * 
     * @return ETLStepInterface
     */
    public function getStep(): ETLStepInterface
    {
        return $this->step;
    }

    /**
     * @return LogBookInterface|null
     */
    public function getLogBook() : ?LogBookInterface
    {
        return $this->logBook;
    }
}