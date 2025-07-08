<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteInterface;
use axenox\ETL\Interfaces\NoteTakerInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

/**
 * @inheritdoc 
 * @see NoteTakerInterface
 */
class NoteTaker implements NoteTakerInterface
{
    /**
     * @var NoteTakerInterface[] 
     */
    protected static array $noteTakers = [];
    protected DataSheetInterface $pendingNotes;
    protected bool $hasPendingNotes = false;
    protected int $currentOrderingId = 0;

    /**
     * @param MetaObjectInterface $storageObject
     */
    public function __construct(MetaObjectInterface $storageObject)
    {
        $this->pendingNotes = DataSheetFactory::createFromObject($storageObject);
    }

    /**
     * @inheritdoc
     */
    public function __destruct()
    {
        $this->commitPendingNotes();
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::commitPendingNotes() 
     */
    public function commitPendingNotes() : void
    {
        if(!$this->hasPendingNotes()) {
            return;
        }
        
        $this->pendingNotes->dataCreate();
        $this->pendingNotes->removeRows();

        $this->hasPendingNotes = false;
        $this->currentOrderingId = 0;
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::getPendingNotes()
     */
    public function getPendingNotes() : DataSheetInterface
    {
        return $this->pendingNotes->copy();
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::hasPendingNotes()
     */
    public function hasPendingNotes() : bool
    {
        return $this->hasPendingNotes;
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::addNote()
     */
    public function addNote(NoteInterface $note) : void
    {
        $data = $note->getNoteData();
        $data['ordering_id'] = $this->currentOrderingId++;
        
        $this->pendingNotes->addRow($data);
        $this->hasPendingNotes = true;
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::takeNote()
     */
    public static function takeNote(NoteInterface $note) : void
    {
        $storageObject = $note->getStorageObject();
        self::getNoteTakerInstance($storageObject)->addNote($note);
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::getNoteTakerInstance()
     */
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

    /**
     * @inheritdoc
     * @see NoteTakerInterface::commitPendingNotesAll()
     */
    public static function commitPendingNotesAll() : void
    {
        foreach (self::$noteTakers as $noteTaker) {
            $noteTaker->commitPendingNotes();
        }
    }
}