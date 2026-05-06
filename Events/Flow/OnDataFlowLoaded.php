<?php
namespace axenox\ETL\Events\Flow;

use axenox\ETL\Interfaces\DataFlowInterface;
use exface\Core\Events\AbstractEvent;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Event triggered when a `DataFlow` is done loading and ready to be altered.
 *
 * @event axenox.ETL.Flow.OnDataFlowLoaded
 *
 * @author Georg Bieger
 *
 */
class OnDataFlowLoaded extends AbstractEvent
{
    private DataFlowInterface $dataFlow;
    
    private ?LogBookInterface $logBook = null;

    /**
     * @param DataFlowInterface     $dataFlow
     * @param LogBookInterface|null $logBook
     */
    public function __construct(DataFlowInterface $dataFlow, LogBookInterface $logBook = null)
    {
        $this->dataFlow = $dataFlow;
        $this->logBook = $logBook;
    }
    
    /**
     * {@inheritdoc}
     * @see AbstractEvent::getEventName()
     */
    public static function getEventName() : string
    {
        return "axenox.ETL.Flow.OnDataFlowLoaded";
    }
    
    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->dataFlow->getWorkbench();
    }

    /**
     *
     * @return DataFlowInterface
     */
    public function getDataFlow(): DataFlowInterface
    {
        return $this->dataFlow;
    }

    /**
     * @return LogBookInterface|null
     */
    public function getLogBook() : ?LogBookInterface
    {
        return $this->logBook;
    }
}