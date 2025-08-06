<?php

namespace axenox\ETL\Common\Traits;

use axenox\ETL\Common\StepNote;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MessageTypeDataType;
use exface\Core\DataTypes\StringDataType;

/**
 * This trait contains all functions and accessors necessary to enable a class to take step notes.
 * @see StepNote
 */
trait ITakeStepNotesTrait
{
    private ?UxonObject $noteOnSuccessUxon = null;
    private ?UxonObject $noteOnFailureUxon = null;

    /**
     * Define a note that will be taken, on success.
     *
     * @uxon-property note_on_success
     * @uxon-type \axenox\etl\Common\StepNote
     * @uxon-template {"message":"", "message_type":"info"}
     *
     * @param UxonObject $uxon
     * @return $this
     */
    public function setNoteOnSuccess(UxonObject $uxon) : static
    {
        $this->noteOnSuccessUxon = $uxon;
        return $this;
    }

    /**
     * Generates a new step note, using the `note_on_success` UXON.
     *
     * @param ETLStepDataInterface $stepData
     * @return StepNote|null
     */
    public function getNoteOnSuccess(ETLStepDataInterface $stepData) : ?StepNote
    {
        if($this->noteOnSuccessUxon === null) {
            return null;
        }
        
        return new StepNote(
            $stepData,
            '',
            MessageTypeDataType::SUCCESS,
            $this->noteOnSuccessUxon
        );
    }
    
    /**
     * Define a note that will be taken, on failure.
     *
     * @uxon-property note_on_failure
     * @uxon-type \axenox\etl\Common\StepNote
     * @uxon-template {"message":"", "message_type":"warning"}
     *
     * @param UxonObject $uxon
     * @return $this
     */
    public function setNoteOnFailure(UxonObject $uxon) : static
    {
        $this->noteOnFailureUxon = $uxon;
        return $this;
    }

    /**
     * Generates a new step note, using the `note_on_failure` UXON.
     *
     * @param ETLStepDataInterface $stepData
     * @param \Throwable           $exception
     * @return StepNote|null
     */
    public function getNoteOnFailure(ETLStepDataInterface $stepData, \Throwable $exception) : ?StepNote
    {
        $note = StepNote::fromException($stepData, $exception);
        
        if($this->noteOnFailureUxon !== null) {
            $msg = $note->getMessage();
            $note->importUxonObject($this->noteOnFailureUxon);
            $noteMsg = $note->getMessage();
            $noteMsg = empty($noteMsg) ? $noteMsg : StringDataType::endSentence($noteMsg);
            
            $note->setMessage($noteMsg . (empty($noteMsg) ? '' : ' ') . $msg);
        }
        
        return $note;
    }
}