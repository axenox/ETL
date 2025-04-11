<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteInterface;
use axenox\ETL\Interfaces\NoteTakerInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;


class NoteTaker implements NoteTakerInterface
{
    /**
     * @var NoteTakerInterface[] 
     */
    protected static array $noteTakers = [];
    protected DataSheetInterface $pendingNotes;
    protected bool $hasPendingNotes = false;

    public function __construct(MetaObjectInterface $storageObject)
    {
        $this->pendingNotes = DataSheetFactory::createFromObject($storageObject);
    }

    public function __destruct()
    {
        $this->commitPendingNotes();
    }
    
    public function commitPendingNotes() : void
    {
        if(!$this->hasPendingNotes()) {
            return;
        }
        
        $this->pendingNotes->dataCreate();
        $this->pendingNotes->removeRows();

        $this->hasPendingNotes = false;
    }
    
    public function getPendingNotes() : DataSheetInterface
    {
        return $this->pendingNotes->copy();
    }
    
    public function hasPendingNotes() : bool
    {
        return $this->hasPendingNotes;
    }
    
    public function addNote(NoteInterface $note) : void
    {
        $this->pendingNotes->addRow($note->getNoteData());
        $this->hasPendingNotes = true;
    }
    
    public static function takeNote(NoteInterface $note) : void
    {
        $storageObject = $note->getStorageObject();
        self::getNoteTakerInstance($storageObject)->addNote($note);
    }

    public static function getNoteTakerInstance(MetaObjectInterface $storageObject) : NoteTakerInterface
    {
        $alias = $storageObject->getAlias();
        if($noteTaker = self::$noteTakers[$alias]) {
            return $noteTaker;
        }
        
        $noteTaker = new NoteTaker($storageObject);
        self::$noteTakers[$alias] = $noteTaker;
        
        return $noteTaker;
    }

    public static function commitPendingNotesAll() : void
    {
        foreach (self::$noteTakers as $noteTaker) {
            $noteTaker->commitPendingNotes();
        }
    }
}