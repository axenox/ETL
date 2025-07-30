<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\ETLStepDataInterface;
use axenox\ETL\Interfaces\NoteInterface;
use axenox\ETL\Interfaces\NoteTakerInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * An implementation of `AbstractNoteTaker` for `StepNote`.
 * @see AbstractNoteTaker
 * @see NoteTakerInterface
 */
class StepNoteTaker extends AbstractNoteTaker 
{
    private ETLStepDataInterface $stepData;
    
    /**
     * @inheritDoc
     * @see AbstractNoteTaker::getStorageObjectAlias()
     */
    protected static function getStorageObjectAlias(): string
    {
        return 'axenox.ETL.step_note';
    }

    /**
     * @inheritDoc
     */
    public static function getInstance(
        WorkbenchInterface $workbench, 
        ETLStepDataInterface $stepData = null
    ) : StepNoteTaker
    {
        $instance = self::locateInstance($workbench);

        if($stepData !== null) {
            $instance->setStepData($stepData);
            self::$instances[self::class] = $instance;
        }
        
        assert(
            $instance->stepData !== null,
            'StepNoteTaker requires step data to work properly.'
        );

        return $instance;
    }

    /**
     * @return ETLStepDataInterface
     */
    public function getStepData() : ETLStepDataInterface
    {
        return $this->stepData;
    }

    /**
     * @param ETLStepDataInterface $stepData
     * @return $this
     */
    public function setStepData(ETLStepDataInterface $stepData) : StepNoteTaker 
    {
        $this->stepData = $stepData;
        return $this;
    }

    /**
     * @inheritDoc
     * @see NoteTakerInterface::createNote()
     */
    public function createNote(
        string $message, 
        \Throwable|string $messageTypeOrException = null, 
        ?UxonObject $uxon = null
    ): NoteInterface
    {
        return new StepNote(
            $this->getWorkbench(), 
            $this->stepData, 
            $message, 
            $messageTypeOrException, 
            $uxon
        );
    }

    /**
     * @inheritDoc
     * @see NoteTakerInterface::createNoteEmpty()
     */
    public function createNoteEmpty(): NoteInterface
    {
        return new StepNote(
            $this->getWorkbench(),
            $this->stepData,
            ''
        );
    }
}