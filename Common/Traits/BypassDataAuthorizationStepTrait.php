<?php
namespace axenox\ETL\Common\Traits;

use exface\Core\CommonLogic\Security\Authorization\DataAuthorizationPoint;
use exface\Core\Interfaces\Debug\LogBookInterface;

/**
 * Contains methods to allow a flow step to disable the data authorization point temporarily
 * 
 * @author Andrej Kabachnik
 *
 */
trait BypassDataAuthorizationStepTrait
{
    private ?bool $bypassDataAuthorizationPoint = null;
    private ?bool $bypassDataAuthorizationWasDisabled = null;

    protected function disableDataAuthorization(?LogBookInterface $logbook = null) : void
    {
        if ($this->willBypassDataAuthorizationPoint() === false) {
            return;
        }
        try {
            $dataAP = $this->getWorkbench()->getSecurity()->getAuthorizationPoint(DataAuthorizationPoint::class);
            $this->bypassDataAuthorizationWasDisabled = $dataAP->isDisabled();
            $dataAP->setDisabled(true);
        } catch (throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            $logbook?->addLine('FAILED to disable data authorization: ' . $e->getMessage());
        }
    }
    
    protected function restoreDataAuthorizationPoint(?LogBookInterface $logbook = null) : void
    {
        if ($this->bypassDataAuthorizationWasDisabled === false) {
            try {
                $dataAP = $this->getWorkbench()->getSecurity()->getAuthorizationPoint(DataAuthorizationPoint::class);
                $dataAP->setDisabled(false);
                $this->bypassDataAuthorizationWasDisabled = null;
            } catch (throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e);
                $logbook?->addLine('FAILED to re-enable data authorization: ' . $e->getMessage());
            }
        }
    }

    /**
     * Returns TRUE or FALSE if `bypass_data_authorization_point` is explicitly set and NULL otherwise
     *
     * @return bool|null
     */
    protected function willBypassDataAuthorizationPoint() : ?bool
    {
        return $this->bypassDataAuthorizationPoint;
    }

    /**
     * Set to TRUE to disable data authorization for this step or to FALSE to force data authorization explicitly
     *
     * Most flow steps, supporting this feature, will disable the data authorization point temporarily to ensure
     * they can do their job regardless of the user they are run by. Using this property you can explicitly
     * control this.
     *
     * @uxon-property bypass_data_authorization_point
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return static
     */
    protected function setBypassDataAuthorizationPoint(bool $value) : static
    {
        $this->bypassDataAuthorizationPoint = $value;
        return $this;
    }
}