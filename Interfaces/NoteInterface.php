<?php

namespace axenox\ETL\Interfaces;

use axenox\ETL\Common\NoteTaker;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * A note is a simple data package: It contains a message, logging level and optionally, 
 * exception data. Each implementation might add additional details, such as aggregation data
 * or relations to any associated meta objects. Each note will ultimately be stored in some
 * data source, specified by its storage object.
 * 
 * Crucially, notes are deferred and do not require an immediate transaction. The `NoteTaker`
 * class automatically caches all notes taken. All pending notes are automatically commited, whenever
 * the calling context is shut down (i.e. on `__destruct()`). In addition, you can commit all pending notes 
 * manually via `NoteTaker::commitPendingNotesAll()`.
 * 
 * @see NoteTaker
 * @see NoteTaker::commitPendingNotesAll()
 */
interface NoteInterface extends WorkbenchDependantInterface
{
    /**
     * Take this note, adding it to the pending notes to be commited later.
     * 
     * @return void
     */
    function takeNote() : void;

    /**
     * The storage object determines how and where this note will ultimately end up being stored.
     * 
     * @return MetaObjectInterface
     */
    function getStorageObject() : MetaObjectInterface;

    /**
     * Generate array of all data contained in this note. The resulting array can be added as a row 
     * to any data sheet based on the storage object.
     * 
     * @return array
     * @see NoteInterface::getStorageObject()
     * @see DataSheetInterface::addRow()
     */
    function getNoteData() : array;

    /**
     * A user readable, translatable message.
     * 
     * @param string $message
     * @return NoteInterface
     */
    function setMessage(string $message) : NoteInterface;

    /**
     * @return string|null
     */
    function getMessage() : ?string;

    /**
     * @param string $logLevel
     * @return NoteInterface
     */
    function setLogLevel(string $logLevel) : NoteInterface;

    /**
     * @return string|null
     */
    function getLogLevel() : ?string;

    /**
     * @param bool $value
     * @return NoteInterface
     */
    function setExceptionFlag(bool $value) : NoteInterface;

    /**
     * TRUE, if this note contains exception data.
     * 
     * @return bool
     */
    function getExceptionFlag() : bool;

    /**
     * @param string $message
     * @return NoteInterface
     */
    function setExceptionMessage(string $message) : NoteInterface;

    /**
     * @return string|null
     */
    function getExceptionMessage() : ?string;

    /**
     * @param string $logId
     * @return NoteInterface
     */
    function setExceptionLogId(string $logId) : NoteInterface;

    /**
     * @return string|null
     */
    function getExceptionLogId() : ?string;
}