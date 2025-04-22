<?php
namespace axenox\ETL\Common;

use axenox\ETL\Common\Traits\ITakeStepNotesTrait;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\Exceptions\DataSheets\DataCheckFailedErrorMultiple;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use axenox\ETL\Interfaces\ETLStepInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use axenox\ETL\Interfaces\ETLStepDataInterface;

abstract class AbstractETLPrototype implements ETLStepInterface
{
    use ImportUxonObjectTrait;
    use ITakeStepNotesTrait;
    
    const PH_PARAMETER_PREFIX = '~parameter:';
    
    const PH_LAST_RUN_PREFIX = 'last_run_';
    
    const PH_LAST_RUN_UID = 'last_run_uid';
    
    const PH_FLOW_RUN_UID = 'flow_run_uid';
    
    const PH_STEP_RUN_UID = 'step_run_uid';
    
    private $workbench = null;
    
    private $uxon = null;
    
    private $stepRunUidAttributeAlias = null;
    
    private $flowRunUidAttribtueAlias = null;
    
    private $fromObject = null;
    
    private $toObject = null;
    
    private $name = null;
    
    private $disabled = null;
    
    private $timeout = 30;
    private ?UxonObject $toDataChecksUxon = null;
    private ?UxonObject $fromDataChecksUxon = null;
    private CrudCounter $crudCounter;

    public function __construct(string $name, MetaObjectInterface $toObject, MetaObjectInterface $fromObject = null, UxonObject $uxon = null)
    {
        $this->workbench = $toObject->getWorkbench();
        $this->uxon = $uxon;
        $this->fromObject = $fromObject;
        $this->toObject = $toObject;
        $this->name = $name;
        $this->crudCounter = new CrudCounter($this->workbench);
        
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return $this->uxon ?? new UxonObject();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::getName()
     */
    public function getName() : string
    {
        return $this->name;
    }
    
    /**
     * 
     * @param string $name
     * @return string|UxonObject
     */
    protected function getConfigProperty(string $name)
    {
        return $this->uxon->getProperty($name);
    }
    
    /**
     * 
     * @param string $name
     * @return bool
     */
    protected function hasConfigProperty(string $name) : bool
    {
        return $this->uxon->hasProperty($name);
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getStepRunUidAttributeAlias() : ?string
    {
        return $this->stepRunUidAttributeAlias;
    }
    
    /**
     * Alias of the attribute of the to-object where the UID of every step run is to be saved
     * 
     * @uxon-property step_run_uid_attribute
     * @uxon-type metamodel:attribute
     * 
     * @param string $value
     * @return AbstractETLPrototype
     */
    protected function setStepRunUidAttribute(string $value) : AbstractETLPrototype
    {
        $this->stepRunUidAttributeAlias = $value;
        return $this;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getFlowRunUidAttributeAlias() : ?string
    {
        return $this->flowRunUidAttribtueAlias;
    }
    
    /**
     * Alias of the attribute of the to-object where the UID of the flow run is to be saved (same value for all steps
     * in a flow)
     * 
     * @uxon-property flow_run_uid_attribute
     * @uxon-type metamodel:attribute
     * 
     * @param string $value
     * @return AbstractETLPrototype
     */
    protected function setFlowRunUidAttribute(string $value) : AbstractETLPrototype
    {
        $this->flowRunUidAttribtueAlias = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::getFromObject()
     */
    public function getFromObject() : MetaObjectInterface
    {
        return $this->fromObject;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::getToObject()
     */
    public function getToObject() : MetaObjectInterface
    {
        return $this->toObject;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::isDisabled()
     */
    public function isDisabled() : bool
    {
        return $this->disabled;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::setDisabled()
     */
    public function setDisabled(bool $value) : ETLStepInterface
    {
        $this->disabled = $value;
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    public function __toString() : string
    {
        return $this->getName();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::getTimeout()
     */
    public function getTimeout() : int
    {
        return $this->timeout;
    }
    
    /**
     * Number of seconds the step is allowed to run at maximum.
     * 
     * @uxon-property timeout
     * @uxon-type integer
     * @uxon-default 30
     * 
     * @param int $seconds
     * @return ETLStepInterface
     */
    public function setTimeout(int $seconds) : ETLStepInterface
    {
        $this->timeout = $seconds;
        return $this;
    }
    
    /**
     * Returns an array with names and values for placeholders that can be used in the steps config.
     * 
     * @param string $stepRunUid
     * @param ETLStepResultInterface $lastResult
     * @return string[]
     */
    protected function getPlaceholders(ETLStepDataInterface $stepData) : array
    {
    	// map identifier to placeholders
        $phs = [
        	self::PH_FLOW_RUN_UID => $stepData->getFlowRunUid(),
        	self::PH_STEP_RUN_UID => $stepData->getStepRunUid()
        ];
        
        $lastResult = $stepData->getLastResult();
        if ($lastResult === null) {
            $lastResult = static::parseResult('');
        }
        
        $phs[self::PH_LAST_RUN_UID] = $lastResult->getStepRunUid();
        foreach ($lastResult->exportUxonObject(true)->toArray() as $ph => $val) {
            if (is_scalar($val) || $val === null) {
                $phs[self::PH_LAST_RUN_PREFIX . $ph] = $val ?? '';
            }
        }
        
        // map query parameter to placeholders
        $task = $stepData->getTask();
        foreach ($task->getParameters() as $name => $value) {
        	$phs[self::PH_PARAMETER_PREFIX . $name] = $value;
        }
        return $phs;
    }

    /**
     * Performs all data checks defined in a given UXON.
     *
     * NOTE: All rows that fail at least one data check will be marked as invalid in the `is_valid_attribute` column on
     * `dataSheet`. You can use this information to ignore them in future processing. If `stop_on_failed_check` is
     * TRUE, the step will be terminated, if at least one row failed at least one data check. In either case, all
     * checks will be performed first.
     *
     * @param DataSheetInterface $dataSheet
     * @param UxonObject|null    $uxon
     * @param string             $flowRunUid
     * @param string             $stepRunUid
     * @return void
     */
    protected function performDataChecks(
        DataSheetInterface $dataSheet, 
        ?UxonObject $uxon,
        string $flowRunUid,
        string $stepRunUid) : void
    {
        if($uxon === null) {
            return;
        }
        
        $errors = null;
        $stopOnError = false;
        
        foreach ($uxon as $dataCheckUxon) {
            $check = new DataCheckWithStepNote(
                $this->getWorkbench(), 
                $dataCheckUxon,
                null,
                $this
            );
            
            if(!$check->isApplicable($dataSheet)) {
                continue;
            }

            try {
                $check->check($dataSheet, null, $flowRunUid, $stepRunUid);
            } catch (DataCheckFailedErrorMultiple $e) {
                $errors = $errors ?? new DataCheckFailedErrorMultiple('', null, null, $this->getWorkbench()->getCoreApp()->getTranslator());
                $errors->merge($e);
                
                $stopOnError |= $check->getStopOnCheckFailed();
            }
        }
        
        if($errors !== null && $stopOnError) {
            throw $errors;
        }
    }

    /**
     * Define a set of data checks to performed on the data RECEIVED by this step. Use the property
     * `stop_on_failed_check` to control, whether a failed check should halt the procedure.
     *
     * NOTE: You can configure per step, whether it should generate a step note on success and/or failure.
     *
     * @uxon-property from_data_checks
     * @uxon-type \axenox\etl\Common\DataCheckWithStepNote[]
     * @uxon-template [{"is_valid_alias":"","is_invalid_value":false, "stop_on_check_failed":false, "note_on_success":{"message":"Check Passed", "log_level":"info"}, "note_on_failure": {"message":"Check Failed", "log_level":"info"}, "conditions":[{"expression":"","comparator":"==","value":""}]}]
     *
     * @param UxonObject $uxon
     * @return $this
     */
    public function setFromDataChecks(UxonObject $uxon) : AbstractETLPrototype
    {
        $this->fromDataChecksUxon = $uxon;
        return $this;
    }

    /**
     * @return UxonObject|null
     */
    public function getFromDataChecksUxon() : ?UxonObject
    {
        return $this->fromDataChecksUxon;
    }

    /**
     * Define a set of data checks to performed on the data PRODUCED by this step.
     * The checks will be applied just before the result data is committed. Use the property
     * `stop_on_failed_check` to control, whether a failed check should halt the procedure.
     *
     * NOTE: You can configure per step, whether it should generate a step note on success and/or failure.
     *
     * @uxon-property to_data_checks
     * @uxon-type \axenox\etl\Common\DataCheckWithStepNote[]
     * @uxon-template [{"is_valid_alias":"","is_invalid_value":false, "stop_on_check_failed":false, "note_on_success":{"message":"Check Passed", "log_level":"info"}, "note_on_failure": {"message":"Check Failed", "log_level":"info"}, "conditions":[{"expression":"","comparator":"==","value":""}]}]
     *
     * @param UxonObject $uxon
     * @return $this
     */
    public function setToDataChecks(UxonObject $uxon) : AbstractETLPrototype
    {
        $this->toDataChecksUxon = $uxon;
        return $this;
    }

    /**
     * @return UxonObject|null
     */
    public function getToDataChecksUxon() : ?UxonObject
    {
        return $this->toDataChecksUxon;
    }

    /**
     * @return CrudCounter
     */
    public function getCrudCounter() : CrudCounter
    {
        return $this->crudCounter;
    }
}