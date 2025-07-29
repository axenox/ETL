<?php

namespace axenox\ETL\Interfaces;

use exface\Core\CommonLogic\UxonObject;
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
     * Commits all currently pending notes for this instance and clears its cache.
     * 
     * @return void
     */
    public function commitPendingNotes() : void;

    /**
     * TRUE, if this instance contains any pending notes.
     * 
     * @return bool
     */
    public function hasPendingNotes() : bool;

    /**
     * Creates a new note with the provided data.
     *
     * @param string                 $message
     * @param \Throwable|string|null $messageTypeOrException
     * @param UxonObject|null        $uxon
     * @return NoteInterface
     */
    public function createNote(
        string            $message,
        \Throwable|string $messageTypeOrException = null,
        ?UxonObject       $uxon = null,
    ) : NoteInterface;

    /**
     * Creates an empty note.
     * 
     * @return NoteInterface
     */
    public function createNoteEmpty() : NoteInterface;

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
     * Get the `NoteTaker` instance for a given storage object.
     *
     * SINGLETON: If that instance does not exist, a new one will be created.
     *
     * @param WorkbenchInterface $workbench
     * @return NoteTakerInterface
     */
    public static function getInstance(WorkbenchInterface $workbench) : NoteTakerInterface;

    /**
     * Commits all pending notes, across all `NoteTaker` instances, to their respective data sources.
     * 
     * NOTE: It is recommended you manually commit pending notes, by calling this function to ensure
     * timing. While pending notes are automatically committed on `__destruct()`, mechanisms like `TimeStampingBehavior`
     * might not function as expected. Each commit, be it manual or automatic, clears the pending notes cache, which 
     * means repeated commits are allowed and don't cause any issues.
     * 
     * @return void
     */
    public static function commitPendingNotesAll() : void;
}