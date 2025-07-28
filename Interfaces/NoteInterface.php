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
    const VISIBLE_FOR_SUPERUSER = ['SUPERUSER'];
    const VISIBLE_FOR_EVERYONE = ['AUTHENTICATED'];
    
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
     * @return string|null
     */
    function getMessageCode() : ?string;

    /**
     * @param string $messageType
     * @return NoteInterface
     */
    function setMessageType(string $messageType) : NoteInterface;

    /**
     * @return string|null
     */
    function getMessageType() : ?string;

    /**
     * @param \Throwable|null $exception
     * @return void
     */
    function setException(?\Throwable $exception) : void;

    /**
     * @return \Throwable|null
     */
    function getExceptionMessage() : ?string;

    /**
     * @return string|null
     */
    function getExceptionLogId() : ?string;
    
    /**
     * @return bool
     */
    function hasException() : bool;

    /**
     * Add context data for this note. The data provided will be merged
     * with any context data already present.
     * 
     * @param array $contextData
     * @return NoteInterface
     */
    function addContextData(array $contextData) : NoteInterface;

    /**
     * Get any context data currently attached to this note.
     * 
     * @return array
     */
    function getContextData() : array;

    /**
     * Returns an array with `exface.Core.USER_ROLE` aliases that this note
     * should be visible for. 
     * 
     * @return array
     */
    function getVisibleForUserRoles() : array;

    /**
     * Set which `exface.Core.USER_ROLE` aliases this note should be visible for.
     * Default is `AUTHENTICATED` (visible for everyone).
     *
     * @param string|array $roles
     * @return NoteInterface
     */
    function setVisibleUserRoles(string|array $roles) : NoteInterface;
}