<?php

namespace axenox\ETL\Common\Traits;

use axenox\ETL\Common\StepNote;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use exface\Core\CommonLogic\UxonObject;

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
        
        return StepNote::FromUxon(
            $this->getWorkbench(),
            $stepData,
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
        if($this->noteOnFailureUxon === null) {
            return StepNote::fromException($this->getWorkbench(), $stepData, $exception);
        }

        return StepNote::FromUxon(
            $this->getWorkbench(),
            $stepData,
            $this->noteOnFailureUxon,
            $exception
        );
    }
}