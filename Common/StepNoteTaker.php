<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteTakerInterface;

/**
 * An implementation of `AbstractNoteTaker` for `StepNote`.
 * 
 * @see AbstractNoteTaker
 * @see NoteTakerInterface
 */
class StepNoteTaker extends AbstractNoteTaker 
{
    /**
     * @inheritDoc
     * @see AbstractNoteTaker::getStorageObjectAlias()
     */
    protected static function getStorageObjectAlias(): string
    {
        return 'axenox.ETL.step_note';
    }
}