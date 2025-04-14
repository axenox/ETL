<?php

namespace axenox\ETL\Common;

use axenox\ETL\Common\Traits\ITakeStepNotesTrait;
use exface\Core\CommonLogic\DataSheets\DataCheck;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\DataSheets\DataCheckFailedError;
use exface\Core\Exceptions\DataSheets\DataCheckRuntimeError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

// TODO Strong dependency with AbstractETLPrototype
class DataCheckWithStepNote extends DataCheck
{
    use ITakeStepNotesTrait;
    
    private ?string $isValidAlias = null;
    private ?AbstractETLPrototype $step = null;
    private string $isInvalidValue = "true";

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

    public function check(DataSheetInterface $sheet, LogBookInterface $logBook = null, string $flowRunUid = '', $stepRunUid = ''): DataSheetInterface
    {
        $toObject = $this->step ? $this->step->getToObject() : $sheet->getMetaObject();
        $isValidAlias = $this->getIsValidAlias();
        
        if(!$toObject->getAttribute($isValidAlias)) {
            throw new DataCheckRuntimeError(
                $sheet, 
                'Attribute ' . $isValidAlias . '("is_valid_alias") not found in object ' . $toObject->getAlias() . '!',
                null,
                null,
                $this
            );
        }
        
        try{
            parent::check($sheet, $logBook); 
        } catch (DataCheckFailedError $error) {
            if($noteOnFailure = $this->getNoteOnFailure($flowRunUid, $stepRunUid, $error)) {
                $noteOnFailure->takeNote();
            }
            
            if(!$sheet->getColumns()->getByExpression($isValidAlias)) {
                $missingSheet = DataSheetFactory::createFromObject($sheet->getMetaObject());
                $missingSheet->getColumns()->addFromUidAttribute();
                $missingSheet->getColumns()->addFromExpression($isValidAlias);
                $missingSheet->getFilters()->addConditionFromColumnValues($sheet->getUidColumn());
                $missingSheet->dataRead();
                
                $sheet->joinLeft($missingSheet, $sheet->getUidColumnName(), $missingSheet->getUidColumnName());
            }
            
            foreach ($error->getBadData()->getRows() as $badRow) {
                $badRow[$isValidAlias] = $this->getIsInvalidValue();
                $sheet->addRow($badRow, true);
            }
            
            throw $error;
        }
        
        if($noteOnSuccess = $this->getNoteOnSuccess($flowRunUid, $stepRunUid)) {
            $noteOnSuccess->takeNote();
        }
        
        return $sheet;
    }

    /**
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
    
    public function getIsValidAlias() : ?string
    {
        return $this->isValidAlias;
    }
    
    public function GetStep() : AbstractETLPrototype
    {
        return $this->step;
    }

    /**
     * @uxon-property is_invalid_value
     * @uxon-template true
     * 
     * @param string $value
     * @return $this
     */
    public function setIsInvalidValue(string $value) : DataCheckWithStepNote
    {
        $this->isInvalidValue = $value;
        return $this;
    }
    
    public function getIsInvalidValue() : string
    {
        return $this->isInvalidValue;
    }

    protected function mergeWithTempNote(StepNote $note): StepNote
    {
        return $this->step ? $this->mergeWithTempNote($note) : $note; // TODO Strong dependency with AbstractETLPrototype
    }
}