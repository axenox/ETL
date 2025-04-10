<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

/**
 * The note taker handles writing any kind of notes to the database.
 * 
 * It is not aware of the inner structure of a note, nor does it care about validating its data.
 * Its main purpose therefore, is to provide a centralized management of all note-related transactions.
 * 
 * // TODO I'm not sure if this utility should be static or not. Making it static is easier to implement but 
 * // TODO might cause a lot of undesirable dependencies on the future.
 */
class NoteTaker
{
    protected static array $pendingNotesCache = [];
    
    public static function addNote(NoteInterface $note) : void
    {
        $storageObject = $note->getStorageObject();
        self::getPendingNotesSheet($storageObject)->addRow($note->getNoteData());
    }
    
    public static function getPendingNotesSheet(MetaObjectInterface $object) : DataSheetInterface
    {
        $cacheData = self::$pendingNotesCache[$object->getAlias()];
        if($cacheData !== null) {
            return $cacheData['dataSheet'];
        }
        
        $dataSheet = DataSheetFactory::createFromObject($object);
        $cacheData[$object->getAlias()] = [
            'dataSheet' => $dataSheet
            // TODO Add transaction and other meta data.
        ];
        
        return $dataSheet;
    }
}