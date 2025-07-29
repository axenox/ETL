<?php

namespace axenox\ETL\Common;

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