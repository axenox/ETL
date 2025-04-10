<?php

namespace axenox\ETL\Interfaces;

use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;

interface NoteInterface extends WorkbenchDependantInterface
{
    function getStorageObject() : MetaObjectInterface;
    
    function getNoteData() : array;
    
    function setMessage(string $message) : NoteInterface;
    function getMessage() : ?string;
    
    function setLogLevel(string $logLevel) : NoteInterface;
    function getLogLevel() : ?string;

    function setFailedFlag(bool $value) : NoteInterface;
    function getFailedFlag() : bool;

    function setFailedMessage(string $message) : NoteInterface;
    function getFailedMessage() : ?string;

    function setFailedLogId(string $logId) : NoteInterface;
    function getFailedLogId() : ?string;
}