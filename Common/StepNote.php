<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Each note consists of a user-friendly message and a logging level. Beyond that it collects aggregation data 
 * about transformations that have taken place during this flow step, such as the number of rows read, created or
 * deleted. 
 * 
 * `StepNotes` will be stored in `exface.Core.STEP_NOTES` and can be used to generate user-friendly insights into
 * the data flows they originated from.
 */
class StepNote implements NoteInterface
{
    use ImportUxonObjectTrait;
    
    private WorkbenchInterface $workbench;
    private MetaObjectInterface $storageObject;
    private string $flowRunUid;
    private string $stepRunUid;
    private ?string $message = null;
    private ?string $logLevel = null;
    private bool $exceptionFlag = false;
    private ?string $exceptionMessage = null;
    private ?string $exceptionLogId = null;
    private int $countReads = -1;
    private int $countWrites = -1;
    private int $countCreates = -1;
    private int $countUpdates = -1;
    private int $countDeletes = -1;
    private int $countErrors = -1;
    private int $countWarnings = -1;

    /**
     * @param WorkbenchInterface      $workbench
     * @param string                  $flowRunUid
     * @param string                  $stepRunUid
     * @param ExceptionInterface|null $exception
     * @param UxonObject|null         $uxon
     */
    public function __construct(
        WorkbenchInterface $workbench, 
        string $flowRunUid, 
        string $stepRunUid,
        ExceptionInterface $exception = null,
        UxonObject $uxon = null
    )
    {
        $this->workbench = $workbench;
        $this->storageObject = MetaObjectFactory::createFromString($workbench,'exface.Core.STEP_NOTES');
        $this->flowRunUid = $flowRunUid;
        $this->stepRunUid = $stepRunUid;
        
        if($this->exceptionFlag = $exception !== null) {
            $this->exceptionMessage = $exception->getMessage();
            $this->exceptionLogId = $exception->getId();
        }
        
        if($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    /**
     * @inheritdoc 
     * @see NoteInterface::takeNote()
     */
    public function takeNote() : void
    {
        NoteTaker::takeNote($this);
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getStorageObject()
     */
    function getStorageObject(): MetaObjectInterface
    {
        return $this->storageObject;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getNoteData()
     */
    function getNoteData(): array
    {
        return [
            'FLOW_RUN_UID' => $this->getFlowRunUid(),
            'STEP_RUN_UID' => $this->getStepRunUid(),
            'MESSAGE' => $this->getMessage(),
            'LOG_LEVEL' => $this->getLogLevel(),
            'EXCEPTION_FLAG' => $this->getExceptionFlag(),
            'EXCEPTION_MESSAGE' => $this->getExceptionMessage(),
            'EXCEPTION_LOG_ID' => $this->getExceptionLogId(),
            'COUNT_READS' => $this->getCountReads(),
            'COUNT_WRITES' => $this->getCountWrites(),
            'COUNT_CREATES' => $this->getCountCreates(),
            'COUNT_UPDATES' => $this->getCountUpdates(),
            'COUNT_DELETES' => $this->getCountDeletes(),
            'COUNT_ERRORS' => $this->getCountErrors(),
            'COUNT_WARNINGS' => $this->getCountWarnings()
        ];
    }

    /**
     * @inheritDoc
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    /**
     * @return string
     */
    public function getFlowRunUid() : string
    {
        return $this->flowRunUid;
    }

    /**
     * @return string
     */
    public function getStepRunUid() : string
    {
        return $this->stepRunUid;
    }

    /**
     * Write a user-friendly message that describes what this note is about.
     * 
     * @uxon-property message
     * @uxon-type string
     * 
     * @param string $message
     * @return $this
     * @see NoteInterface::setMessage()
     */
    public function setMessage(string $message) : StepNote
    {
        $this->message = $message;
        return $this;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getMessage()
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Assign a log-level for this note.
     * 
     * @uxon-property log_level
     * @uxon-type [debug,info,notice,warning,error,critical,alert,emergency]
     * 
     * @param string $logLevel
     * @return NoteInterface
     * @see NoteInterface::setLogLevel()
     */
    public function setLogLevel(string $logLevel): NoteInterface
    {
        $this->logLevel = LogLevelDataType::cast($logLevel);
        return $this;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getLogLevel()
     */
    public function getLogLevel(): ?string
    {
        return $this->logLevel;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::setExceptionFlag()
     */
    public function setExceptionFlag(bool $value): NoteInterface
    {
        $this->exceptionFlag = $value;
        return $this;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getExceptionFlag()
     */
    public function getExceptionFlag(): bool
    {
        return $this->exceptionFlag;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::setExceptionMessage()
     */
    public function setExceptionMessage(string $message): NoteInterface
    {
        $this->exceptionMessage = $message;
        return $this;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getExceptionMessage()
     */
    public function getExceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::setExceptionLogId()
     */
    public function setExceptionLogId(string $logId): NoteInterface
    {
        $this->exceptionLogId = $logId;
        return $this;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getExceptionLogId()
     */
    public function getExceptionLogId(): ?string
    {
        return $this->exceptionLogId;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setCountReads(int $count) : StepNote
    {
        $this->countReads = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getCountReads() : int
    {
        return $this->countReads;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setCountWrites(int $count) : StepNote
    {
        $this->countWrites = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getCountWrites() : int
    {
        return $this->countWrites;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setCountCreates(int $count) : StepNote
    {
        $this->countCreates = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getCountCreates() : int
    {
        return $this->countCreates;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setCountUpdates(int $count) : StepNote
    {
        $this->countUpdates = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getCountUpdates() : int
    {
        return $this->countUpdates;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setCountDeletes(int $count) : StepNote
    {
        $this->countDeletes = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getCountDeletes() : int
    {
        return $this->countDeletes;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setCountErrors(int $count) : StepNote
    {
        $this->countErrors = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getCountErrors() : int
    {
        return $this->countErrors;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setCountWarnings(int $count) : StepNote
    {
        $this->countWarnings = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getCountWarnings() : int
    {
        return $this->countWarnings;
    }
}