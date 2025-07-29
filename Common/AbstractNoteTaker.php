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
    /**
     * @var NoteTakerInterface[] 
     */
    protected static array $instances = [];
    protected DataSheetInterface $pendingNotes;
    protected bool $hasPendingNotes = false;
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
        $this->pendingNotes = DataSheetFactory::createFromObject($this->storageObject);
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
     * @see NoteTakerInterface::takeNote()
     */
    public function takeNote(NoteInterface $note) : void
    {
        $data = $note->getNoteData();
        $data['ordering_id'] = $this->currentOrderingId++;
        
        $this->pendingNotes->addRow($data);
        $this->hasPendingNotes = true;
    }

    /**
     * @inheritDoc
     * @see NoteTakerInterface::getInstance()
     */
    public static function getInstance(WorkbenchInterface $workbench): NoteTakerInterface
    {
        $class = self::class;
        $instance = self::$instances[$class];
        if($instance !== null) {
            return $instance;
        }
        
        $instance = new $class($workbench);
        self::$instances[$class] = $instance;
        
        return $instance;
    }


    /**
     * @inheritdoc
     * @see NoteTakerInterface::commitPendingNotesAll()
     */
    public static function commitPendingNotesAll() : void
    {
        foreach (self::$instances as $instance) {
            $instance->commitPendingNotes();
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