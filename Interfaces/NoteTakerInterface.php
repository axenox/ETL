<?php

namespace axenox\ETL\Interfaces;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\TranslationInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * The note taker is responsible for caching notes and commiting them to their respective data sources.
 *
 * It is not aware of the inner structure of a note, nor does it care about validating its data.
 * Its main purpose therefore, is to provide a centralized management of all note-related transactions.
 */
interface NoteTakerInterface extends WorkbenchDependantInterface
{
    /**
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench);

    /**
     * All pending notes must be commited in this function.
     */
    public function __destruct();

    /**
     * Get the metaobject to which this instance writes its notes.
     * 
     * @return MetaObjectInterface
     */
    public function getStorageObject() : MetaObjectInterface;

    /**
     * @return TranslationInterface
     */
    public function getTranslator() : TranslationInterface;

    /**
     * Returns a COPY of the data sheet containing all currently pending notes for this instance.
     * 
     * @return DataSheetInterface
     */
    public function getPendingNotes() : DataSheetInterface;

    /**
     * Commits all currently pending notes for all instances of this note taker class and clears its cache.
     * 
     * @return void
     */
    public static function commitPendingNotes() : void;

    /**
     * TRUE, if this instance contains any pending notes.
     * 
     * @return bool
     */
    public function hasPendingNotes() : bool;

    /**
     * Add a pending note to this instance.
     * 
     * NOTE: This method does not perform deduplication. If the same note is added
     * multiple times, each instance will be treated as a separate note.
     * 
     * @param NoteInterface $note
     * @return void
     */
    public function takeNote(NoteInterface $note) : void;

    /**
     * Commits all pending notes, across all note taker classes.
     * 
     * @return void
     */
    public static function commitPendingNotesAll() : void;
}