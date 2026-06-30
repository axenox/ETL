<?php
namespace axenox\ETL\Events;

use axenox\ETL\Common\WebserviceInfo;
use exface\Core\Events\AbstractEvent;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Event triggered when an `ApiSchema` definition is loaded from storage and is ready to be altered.
 *
 * @event axenox.ETL.OnWebserviceLoaded
 *
 * @author Georg Bieger
 *
 */
class OnWebserviceLoaded extends AbstractEvent
{
    private ?LogBookInterface $logBook;
    private WorkbenchInterface $workbench;
    private WebserviceInfo $webservice;

    /**
     * @param WorkbenchInterface    $workbench
     * @param WebserviceInfo        $webservice
     * @param LogBookInterface|null $logBook
     */
    public function __construct(WorkbenchInterface $workbench, WebserviceInfo $webservice, LogBookInterface $logBook = null)
    {
        $this->logBook = $logBook;
        $this->webservice = $webservice;
        $this->workbench = $workbench;
    }

    /**
     * {@inheritdoc}
     * @see AbstractEvent::getEventName()
     */
    public static function getEventName() : string
    {
        return "axenox.ETL.OnWebserviceLoaded";
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    /**
     * @return LogBookInterface|null
     */
    public function getLogBook() : ?LogBookInterface
    {
        return $this->logBook;
    }
    
    /**
     * @return WebserviceInfo
     */
    public function getWebservice(): WebserviceInfo
    {
        return $this->webservice;
    }
}