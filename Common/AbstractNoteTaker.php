<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteInterface;
use axenox\ETL\Interfaces\NoteTakerInterface;
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
    /**
     * @var DataSheetInterface[] 
     */
    protected static array $pendingNotes = [];
    protected int $currentOrderingId = 0;
    private WorkbenchInterface $workbench;
    private MetaObjectInterface $storageObject;
    private TranslationInterface $translator;

    /**
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
        $this->storageObject = MetaObjectFactory::createFromString($workbench, $this->getStorageObjectAlias());
        $this->translator = $workbench->getCoreApp()->getTranslator();
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
    public function commitPendingNotes() : void
    {
        if(!$this->hasPendingNotes()) {
            return;
        }

        $class = self::class;
        self::$pendingNotes[$class]->dataCreate();
        unset(self::$pendingNotes[$class]);

        $this->currentOrderingId = 0;
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::getPendingNotes()
     */
    public function getPendingNotes() : DataSheetInterface
    {
        return self::$pendingNotes[self::class]->copy();
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::hasPendingNotes()
     */
    public function hasPendingNotes() : bool
    {
        return key_exists(self::class, self::$pendingNotes);
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::takeNote()
     */
    public function takeNote(NoteInterface $note) : void
    {
        $data = $note->getNoteData();
        $data['ordering_id'] = $this->currentOrderingId++;
        
        self::$pendingNotes[self::class]->addRow($data);
    }

    /**
     * @inheritdoc
     * @see NoteTakerInterface::commitPendingNotesAll()
     */
    public static function commitPendingNotesAll() : void
    {
        foreach (self::$pendingNotes as $class => $pendingNotes) {
            (new $class($pendingNotes->getWorkbench()))->commitPendingNotes();
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