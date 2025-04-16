<?php

namespace axenox\ETL\Common;

use axenox\ETL\Common\Traits\ITakeStepNotesTrait;
use exface\Core\CommonLogic\DataSheets\DataCheck;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\DataSheets\DataCheckFailedError;
use exface\Core\Exceptions\DataSheets\DataCheckFailedErrorMultiple;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Works in a very similar fashion to a regular data check, with two key differences:
 * - You can define a step note for both success and failure, which will automatically be taken
 * when the check is passed or failed, respectively.
 * - If you set a value for `is_valid_alias` and a check is failed, the offending row will be marked as invalid
 * in the input sheet. This enables you to separate the bad data in future processing steps.
 * 
 * IMPORTANT: If an item MATCHES the condition of a check, it is considered to have FAILED the check. 
 */
class DataCheckWithStepNote extends DataCheck
{
    use ITakeStepNotesTrait;
    
    private ?string $isValidAlias = null;
    private ?AbstractETLPrototype $step = null;
    private string $isInvalidValue = "false";
    private bool $stopOnCheckFailed;

    /**
     * @param WorkbenchInterface        $workbench
     * @param UxonObject                $uxon
     * @param MetaObjectInterface|null  $onlyForObject
     * @param AbstractETLPrototype|null $step
     */
    public function __construct(
        WorkbenchInterface $workbench, 
        UxonObject $uxon, 
        MetaObjectInterface $onlyForObject = null,
        AbstractETLPrototype $step = null
    )
    {
        parent::__construct($workbench, $uxon, $onlyForObject);
        $this->step = $step;
    }

    /**
     * @param DataSheetInterface    $sheet
     * @param LogBookInterface|null $logBook
     * @param string                $flowRunUid
     * @param string                $stepRunUid
     * @return DataSheetInterface
     */
    public function check(
        DataSheetInterface $sheet, 
        LogBookInterface $logBook = null, 
        string $flowRunUid = '', 
        string $stepRunUid = ''): DataSheetInterface
    {
        $isValidAlias = $this->getIsValidAlias();
        $checkSheet = $sheet->copy();
        $errors = null;
        
        foreach ($sheet->getRows() as $rowNr => $row) {
            $checkSheet->removeRows();
            $checkSheet->addRow($row);

            try {
                parent::check($checkSheet);
            } catch (DataCheckFailedError $e) {
                $errors = $errors ?? new DataCheckFailedErrorMultiple('', null, null, $this->getWorkbench()->getCoreApp()->getTranslator());
                $errors->appendError($e, $rowNr);
                
                if(!empty($isValidAlias)) {
                    $sheet->setCellValue($isValidAlias, $rowNr, $this->getIsInvalidValue());
                }
            }
        }
        
        if($errors) {
            if($noteOnFailure = $this->getNoteOnFailure($flowRunUid, $stepRunUid, $errors)) {
                $noteOnFailure->importCrudCounter($this->getStep()?->getCrudCounter());
                $noteOnFailure->takeNote();
            }
            
            throw $errors;
        } 
        
        if($noteOnSuccess = $this->getNoteOnSuccess($flowRunUid, $stepRunUid)) {
            $noteOnSuccess->importCrudCounter($this->getStep()?->getCrudCounter());
            $noteOnSuccess->takeNote();
        }
        
        return $sheet;
    }

    /**
     * Any row that fails this data check will be marked as invalid in this column.
     * 
     * @uxon-property is_valid_alias
     * @uxon-type metamodel:attribute
     * 
     * @param string $alias
     * @return $this
     */
    public function setIsValidAlias(string $alias) : DataCheckWithStepNote
    {
        $this->isValidAlias = $alias;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getIsValidAlias() : ?string
    {
        return $this->isValidAlias;
    }

    /**
     * @return AbstractETLPrototype|null
     */
    public function getStep() : ?AbstractETLPrototype
    {
        return $this->step;
    }

    /**
     * Here you can define the value that will be used to mark a row as invalid.
     * 
     * @uxon-property is_invalid_value
     * @uxon-template false
     * 
     * @param string $value
     * @return $this
     */
    public function setIsInvalidValue(string $value) : DataCheckWithStepNote
    {
        $this->isInvalidValue = $value;
        return $this;
    }

    /**
     * @return string
     */
    public function getIsInvalidValue() : string
    {
        return $this->isInvalidValue;
    }

    /**
     * @param DataSheetInterface|null $badData
     * @return string|null
     */
    public function getErrorText(DataSheetInterface $badData = null): ?string
    {
        $text = parent::getErrorText($badData);
        if(empty($text) && !empty($this->noteOnFailureUxon)) {
            return $this->noteOnFailureUxon->getProperty('message');
        }
        
        return $text;
    }

    /**
     * If TRUE, the step will be terminated, if at least one row failed this check.
     * In either case, all checks will be performed first.
     *
     * @uxon-property stop_on_check_failed
     * @uxon-type boolean
     * @uxon-template false
     *
     * @param bool $value
     * @return $this
     */
    public function setStopOnCheckFailed(bool $value) : DataCheckWithStepNote
    {
        $this->stopOnCheckFailed = $value;
        return $this;
    }

    /**
     * @return bool
     */
    public function getStopOnCheckFailed() : bool
    {
        return $this->stopOnCheckFailed;
    }
}