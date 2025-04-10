<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class StepNote implements NoteInterface
{
    use ImportUxonObjectTrait;
    
    private WorkbenchInterface $workbench;
    private MetaObjectInterface $storageObject;
    private string $flowRunUid;
    private string $stepRunUid;
    private ?string $message = null;
    private ?string $logLevel = null;
    private bool $failed = false;
    private ?string $failedMessage = null;
    private ?string $failedLogId = null;
    private int $countReads = -1;
    private int $countWrites = -1;
    private int $countCreates = -1;
    private int $countUpdates = -1;
    private int $countDeletes = -1;
    private int $countErrors = -1;
    private int $countWarnings = -1;

    public function __construct(
        WorkbenchInterface $workbench, 
        string $flowRunUid, 
        string $stepRunUid,
        bool $failed,
        UxonObject $uxon = null
    )
    {
        $this->workbench = $workbench;
        $this->storageObject = MetaObjectFactory::createFromString($workbench,'exface.Core.STEP_NOTES');
        $this->flowRunUid = $flowRunUid;
        $this->stepRunUid = $stepRunUid;
        $this->failed = $failed;
        
        if($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    function getStorageObject(): MetaObjectInterface
    {
        return $this->storageObject;
    }

    function getNoteData(): array
    {
        return [
            'FLOW_RUN_UID' => $this->getFlowRunUid(),
            'STEP_RUN_UID' => $this->getStepRunUid(),
            'MESSAGE' => $this->getMessage(),
            'LOG_LEVEL' => $this->getLogLevel(),
            'FAILED_FLAG' => $this->getFailedFlag(),
            'FAILED_MESSAGE' => $this->getFailedMessage(),
            'FAILED_LOG_ID' => $this->getFailedLogId(),
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
    
    public function getFlowRunUid() : string
    {
        return $this->flowRunUid;
    }
    
    public function getStepRunUid() : string
    {
        return $this->stepRunUid;
    }

    /**
     * @uxon-property message
     * @uxon-type string
     * 
     * @param string $message
     * @return $this
     */
    public function setMessage(string $message) : StepNote
    {
        $this->message = $message;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @uxon-property log_level
     * @uxon-type [debug,info,notice,warning,error,critical,alert,emergency]
     * 
     * @param string $logLevel
     * @return NoteInterface
     */
    public function setLogLevel(string $logLevel): NoteInterface
    {
        $this->logLevel = LogLevelDataType::cast($logLevel);
        return $this;
    }

    public function getLogLevel(): ?string
    {
        return $this->logLevel;
    }
    
    public function setFailedFlag(bool $value): NoteInterface
    {
        $this->failed = $value;
        return $this;
    }

    public function getFailedFlag(): bool
    {
        return $this->failed;
    }

    /**
     * @uxon-property failed_message
     * @uxon-type string
     * 
     * @param string $message
     * @return NoteInterface
     */
    public function setFailedMessage(string $message): NoteInterface
    {
        $this->failedMessage = $message;
        return $this;
    }

    public function getFailedMessage(): ?string
    {
        return $this->failedMessage;
    }

    public function setFailedLogId(string $logId): NoteInterface
    {
        $this->failedLogId = $logId;
        return $this;
    }

    public function getFailedLogId(): ?string
    {
        return $this->failedLogId;
    }

    public function setCountReads(int $count) : StepNote
    {
        $this->countReads = $count;
        return $this;
    }
    
    public function getCountReads() : int
    {
        return $this->countReads;
    }

    public function setCountWrites(int $count) : StepNote
    {
        $this->countWrites = $count;
        return $this;
    }

    public function getCountWrites() : int
    {
        return $this->countWrites;
    }

    public function setCountCreates(int $count) : StepNote
    {
        $this->countCreates = $count;
        return $this;
    }

    public function getCountCreates() : int
    {
        return $this->countCreates;
    }

    public function setCountUpdates(int $count) : StepNote
    {
        $this->countUpdates = $count;
        return $this;
    }

    public function getCountUpdates() : int
    {
        return $this->countUpdates;
    }

    public function setCountDeletes(int $count) : StepNote
    {
        $this->countDeletes = $count;
        return $this;
    }

    public function getCountDeletes() : int
    {
        return $this->countDeletes;
    }

    public function setCountErrors(int $count) : StepNote
    {
        $this->countErrors = $count;
        return $this;
    }

    public function getCountErrors() : int
    {
        return $this->countErrors;
    }

    public function setCountWarnings(int $count) : StepNote
    {
        $this->countWarnings = $count;
        return $this;
    }

    public function getCountWarnings() : int
    {
        return $this->countWarnings;
    }
}