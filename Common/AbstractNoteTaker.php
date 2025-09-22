<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteInterface;
use axenox\ETL\Interfaces\NoteTakerInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\TranslationInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * @inheritDoc
 * @see NoteTakerInterface
 */
abstract class AbstractNoteTaker implements NoteTakerInterface
{
    protected const CFG_NOTE_LIMIT = 'NOTE_TAKER.NOTE_LIMIT';
    /**
     * @var DataSheetInterface[] 
     */
    protected static array $pendingNotes = [];
    protected static array $currentOrderingIds = [];
    private WorkbenchInterface $workbench;
    private MetaObjectInterface $storageObject;
    private TranslationInterface $translator;
    private int $noteLimit = -1;

    /**
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
        $this->storageObject = MetaObjectFactory::createFromString($workbench, $this->getStorageObjectAlias());
        $this->translator = $workbench->getCoreApp()->getTranslator();
        self::$currentOrderingIds[get_class()] = 0;
        
        $cfg = $this->workbench->getApp('axenox.ETL')->getConfig();
        if($cfg->hasOption(self::CFG_NOTE_LIMIT)) {
            $this->noteLimit = $cfg->getOption(self::CFG_NOTE_LIMIT);
        }
    }

    /**
     * @inheritdoc
     */
    public function __destruct()
    {
        $this->commitPendingNotes();
    }

    /**
     * @inheritDoc
     * @see NoteTakerInterface::getStorageObject()
     */
    public function getStorageObject(): MetaObjectInterface
    {
        return $this->storageObject;
    }

    /**
     * Get the object alias with name space for this note taker.
     * 
     * @return string
     * @see NoteTakerInterface::getStorageObject()
     */
    protected static abstract function getStorageObjectAlias() : string;

    /**
     * @inheritDoc
     * @see NoteTakerInterface::getTranslator()
     */
    public function getTranslator(): TranslationInterface
    {
        return $this->translator;
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::commitPendingNotes() 
     */
    public static function commitPendingNotes() : void
    {
        $class = get_called_class();
        $pendingNotes = self::$pendingNotes[$class];
        
        if($pendingNotes === null) {
            return;
        }

        $pendingNotes->dataCreate();
        unset(self::$pendingNotes[$class]);

        self::$currentOrderingIds[$class] = 0;
    }

    /**
     * Returns the datasheet by reference containing all currently pending notes 
     * for this note taker class.
     * 
     * @return DataSheetInterface
     */
    protected function getPendingNotesInternal() : DataSheetInterface
    {
        $class = get_called_class();
        $pendingNotes = self::$pendingNotes[$class];
        if($pendingNotes !== null) {
            return $pendingNotes;
        }
        
        $pendingNotes = DataSheetFactory::createFromObject($this->getStorageObject());
        self::$pendingNotes[$class] = $pendingNotes;
        
        return $pendingNotes;
    }
    
    /**
     * @inheritdoc
     * @see NoteTakerInterface::getPendingNotes()
     */
    public function getPendingNotes() : DataSheetInterface
    {
        return $this->getPendingNotesInternal()->copy();
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::hasPendingNotes()
     */
    public function hasPendingNotes() : bool
    {
        return key_exists(get_called_class(), self::$pendingNotes);
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::takeNote()
     */
    public function takeNote(NoteInterface $note) : void
    {
        $data = $note->getNoteData();
        $data['ordering_id'] = self::$currentOrderingIds[get_class()]++;
        
        $pending = $this->getPendingNotesInternal();
        $pending->addRow($data);
        
        if($this->noteLimit > 0 && $pending->countRows() > $this->noteLimit) {
            $pending->removeRow(0);
        }
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::commitPendingNotesAll()
     */
    public static function commitPendingNotesAll() : void
    {
        foreach (self::$pendingNotes as $class => $pendingNotes) {
            $class::commitPendingNotes();
        }
    }

    /**
     * @inheritDoc
     * @see WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }
}