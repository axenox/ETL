<?php
namespace axenox\ETL\Events;

use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use exface\Core\Events\AbstractEvent;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Event triggered when an `ApiSchema` is done loading and ready to be altered.
 *
 * @event axenox.ETL.OnWebServiceLoaded
 *
 * @author Georg Bieger
 *
 */
class OnWebServiceLoaded extends AbstractEvent
{
    private ?LogBookInterface $logBook;
    
    private APISchemaInterface $webservice;

    /**
     * @param APISchemaInterface    $webservice
     * @param LogBookInterface|null $logBook
     */
    public function __construct(APISchemaInterface $webservice, LogBookInterface $logBook = null)
    {
        $this->logBook = $logBook;
        $this->webservice = $webservice;
    }

    /**
     * {@inheritdoc}
     * @see AbstractEvent::getEventName()
     */
    public static function getEventName() : string
    {
        return "axenox.ETL.OnWebServiceLoaded";
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->webservice->getWorkbench();
    }

    /**
     * @return LogBookInterface|null
     */
    public function getLogBook() : ?LogBookInterface
    {
        return $this->logBook;
    }

    /**
     * @return APISchemaInterface
     */
    public function getApiSchema(): APISchemaInterface
    {
        return $this->webservice;
    }

    /**
     * @return APISchemaInterface
     */
    public function getWebservice(): APISchemaInterface
    {
        return $this->webservice;
    }
}