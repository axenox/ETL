<?php

namespace axenox\ETL\Common\Traits;

use axenox\ETL\Common\StepNote;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;

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
     * @uxon-template {"message":"", "log_level":"info"}
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
     * @param string $flowRunUid
     * @param string $stepRunUid
     * @return StepNote|null
     */
    public function getNoteOnSuccess(
        string $flowRunUid,
        string $stepRunUid) : ?StepNote
    {
        if($this->noteOnSuccessUxon === null) {
            return null;
        }
        
        return StepNote::FromUxon(
            $this->getWorkbench(),
            $flowRunUid,
            $stepRunUid,
            $this->noteOnSuccessUxon
        );
    }

    /**
     * Define a note that will be taken, on failure.
     *
     * @uxon-property note_on_failure
     * @uxon-type \axenox\etl\Common\StepNote
     * @uxon-template {"message":"", "log_level":"warning"}
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
     * @param string     $flowRunUid
     * @param string     $stepRunUid
     * @param \Throwable $exception
     * @return StepNote|null
     */
    public function getNoteOnFailure(
        string $flowRunUid,
        string $stepRunUid,
        \Throwable $exception) : ?StepNote
    {
        if($this->noteOnFailureUxon === null) {
            return null;
        }

        return StepNote::FromUxon(
            $this->getWorkbench(),
            $flowRunUid,
            $stepRunUid,
            $this->noteOnFailureUxon,
            $exception
        );
    }
}