<?php

namespace axenox\ETL\Common;

use axenox\ETL\Common\Traits\ITakeStepNotesTrait;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use exface\Core\CommonLogic\DataSheets\DataCheck;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\StringDataType;
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
 * ## Placeholders
 * 
 * - You can use common placeholders (i.e. `[#ATTRIBUTE_ALIAS#]`) that will be rendered with the appropriate cell value.
 * - Placeholders are available within the following properties: `error_text`, `conditions`.
 * 
 * IMPORTANT: If an item MATCHES the condition of a check, it is considered to have FAILED the check. 
 */
class DataCheckWithStepNote extends DataCheck
{
    use ITakeStepNotesTrait;
    
    private ?string $isValidAlias = null;
    private ?AbstractETLPrototype $step = null;
    private mixed $isInvalidValue = false;
    private bool $removeInvalidRows = false;
    private bool $stopOnCheckFailed;
    private array $placeHolders;

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
        $this->placeHolders = StringDataType::findPlaceholders($uxon->toJson());
    }

    /**
     * @param DataSheetInterface        $sheet
     * @param LogBookInterface|null     $logBook
     * @param ETLStepDataInterface|null $stepData
     * @return DataSheetInterface
     */
    public function check(
        DataSheetInterface $sheet, 
        LogBookInterface $logBook = null,
        ETLStepDataInterface $stepData = null
    ) : string
    {
        $removeInvalidRows = $this->getRemoveInvalidRows();
        
        $isInValidValue = $this->getIsInvalidValue();
        $isValidAlias = $this->getIsValidAlias();
        
        if(!empty($isValidAlias)) {
            if($sheet->getMetaObject()->hasAttribute($isValidAlias)) {
                $isInValidValue = $sheet->getMetaObject()->getAttribute($isValidAlias)->getDataType()->format($isInValidValue);
            } else if (is_bool($isInValidValue)) {
                $isInValidValue = $isInValidValue ? 1 : 0;
            }
        }
        
        $checkSheet = $sheet->copy();
        $errors = null;
        $rowsToRemove = [];
        $conditionString = $this->getConditionGroup($checkSheet->getMetaObject())->__toString();
        $conditionJson = $this->getConditionGroupUxon()->toJson();
        
        foreach ($sheet->getRows() as $rowNr => $row) {
            $checkSheet->removeRows();
            $checkSheet->addRow($row);

            // Rendering placeholders.
            $placeHoldersToValues = [];
            if(!empty($this->placeHolders)) {
                foreach ($this->placeHolders as $placeHolder) {
                    $placeHoldersToValues[$placeHolder] = $row[$placeHolder];
                }

                $renderedJson = StringDataType::replacePlaceholders($conditionJson, $placeHoldersToValues);
                $conditionUxon = UxonObject::fromJson($renderedJson)->getProperty('conditions');
                $this->setConditions($conditionUxon);
            }
            
            try {
                $result = parent::check($checkSheet);
            } catch (DataCheckFailedError $e) {
                $placeHolderInfo = '';
                if(!empty($placeHoldersToValues)) {
                    $last = array_key_last($placeHoldersToValues);
                    foreach ($placeHoldersToValues as $key => $value) {
                        $placeHolderInfo .= '[#' . $key . '#]: ' . $value . ($key !== $last ? ', ' : '');
                    }
                    $placeHolderInfo = ' with placeholders `' . $placeHolderInfo . '`';
                }
                $badIdxs = $e->getRowIndexes();
                $logLine = 'Found ' . count($badIdxs) . ' matches for check `' . $conditionString . '`' . $placeHolderInfo . '. Rows indexes ' . image_type_to_extension(', ', $badIdxs);
                
                $errorMessage = StringDataType::replacePlaceholders($e->getMessage(), $placeHoldersToValues);
                
                if($removeInvalidRows) {
                    $rowsToRemove[] = $rowNr;
                    $logBook->addLine($logLine . ' REMOVED matching lines from processing.');
                    $e = new DataCheckFailedError($e->getDataSheet(), $errorMessage . ' (REMOVED)', $e->getAlias(), $e->getPrevious());
                } else if(!empty($isValidAlias)) {
                    $sheet->setCellValue($isValidAlias, $rowNr, $isInValidValue);
                    $logBook->addLine($logLine . ' Marked matching lines as INVALID.');
                    $e = new DataCheckFailedError($e->getDataSheet(), $errorMessage . ' (Marked as INVALID)', $e->getAlias(), $e->getPrevious());
                } else {
                    $logBook->addLine($logLine);
                }
                
                $errors = $errors ?? new DataCheckFailedErrorMultiple('', null, null, $this->getWorkbench()->getCoreApp()->getTranslator());
                $errors->appendError($e, $rowNr + 1);
            }
        }
        
        if(!empty($rowsToRemove)) {
            $sheet->removeRows($rowsToRemove);
        }
        
        if($errors) {
            if($noteOnFailure = $this->getNoteOnFailure($stepData, $errors)) {
                $noteOnFailure->importCrudCounter($this->getStep()?->getCrudCounter());
                $noteOnFailure->setCountErrors(count($errors->getAllErrors()));
                $noteOnFailure->takeNote();
            }
            
            throw $errors;
        } 
        
        if($noteOnSuccess = $this->getNoteOnSuccess($stepData)) {
            $noteOnSuccess->importCrudCounter($this->getStep()?->getCrudCounter());
            $noteOnSuccess->takeNote();
        }
        
        return $result;
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
    public function setIsInvalidValue(mixed $value) : DataCheckWithStepNote
    {
        $this->isInvalidValue = $value;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getIsInvalidValue() : mixed
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
     * If TRUE, rows that fail this data check will be removed from processing.
     * 
     * NOTE: This effectively overwrites flagging invalid data with `is_valid_alias`.
     * 
     * @uxon-property remove_invalid_rows
     * @uxon-type boolean
     * @uxon-template false
     * 
     * @param bool $value
     * @return $this
     */
    public function setRemoveInvalidRows(bool $value) : DataCheckWithStepNote
    {
        $this->removeInvalidRows = $value;
        return $this;
    }

    /**
     * @return bool
     */
    public function getRemoveInvalidRows() : bool
    {
        return $this->removeInvalidRows;
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