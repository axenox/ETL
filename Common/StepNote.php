<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\ETLStepDataInterface;
use axenox\ETL\Interfaces\NoteInterface;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Throwable;

/**
 * Each note consists of a user-friendly message and a logging level. Beyond that it collects aggregation data 
 * about transformations that have taken place during this flow step, such as the number of rows read, created or
 * deleted. 
 * 
 * `StepNotes` will be stored in `axenox.ETL.step_note` and can be used to generate user-friendly insights into
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
    private ?string $messageCode = null;
    private ?string $logLevel = null;
    private bool $exceptionFlag = false;
    private ?string $exceptionMessage = null;
    private ?string $exceptionLogId = null;
    private ?int $countReads = null;
    private ?int $countWrites = null;
    private ?int $countCreates = null;
    private ?int $countUpdates = null;
    private ?int $countDeletes = null;
    private ?int $countErrors = null;
    private ?int $countWarnings = null;

    /**
     * @param WorkbenchInterface   $workbench
     * @param ETLStepDataInterface $stepData
     * @param string               $message
     * @param Throwable|null       $exception
     * @param string|null          $logLevel
     */
    public function __construct(
        WorkbenchInterface $workbench,
        ETLStepDataInterface $stepData,
        string $message = "",
        Throwable $exception = null,
        string $logLevel = null,
    )
    {
        $this->workbench = $workbench;
        $this->storageObject = MetaObjectFactory::createFromString($workbench,'axenox.ETL.step_note');
        $this->flowRunUid = $stepData->getFlowRunUid();
        $this->stepRunUid = $stepData->getStepRunUid();
        $this->message = $message;
        $this->logLevel = $logLevel ?? ($exception ? 'error' : 'info');
        
        if($this->exceptionFlag = $exception !== null) {
            $this->exceptionMessage = $exception->getMessage();
            
            if($exception instanceof ExceptionInterface) {
                $this->exceptionLogId = $exception->getId();
                if ($this->message === "") {
                    switch (true) {
                        /* TODO Create DataSheetValueExceptionInterface and implement it
                        * - DataSheetMissingRequiredValueError
                        * - DataSheetInvalidValueError
                        * - LATER in DataSheetDuplicatesError, which currently does not have any information about rows
                        * other than in its message
                        case $exception instanceof DataSheetValueExceptionInterface:
                        case $exception instanceof DataSheetInvalidValueError:
                            $this->message = $exception->getMessageWithoutRowNumbers();*/
                        default:
                            $this->message = $exception->getMessageModel($this->getWorkbench())->getTitle();
                    }
                }
            }
        }
    }

    /**
     * Create a new step note instance, using a UXON config.
     *
     * @param WorkbenchInterface   $workbench
     * @param ETLStepDataInterface $stepData
     * @param UxonObject           $uxon
     * @param Throwable|null       $exception
     * @return StepNote
     */
    public static function FromUxon(
        WorkbenchInterface $workbench,
        ETLStepDataInterface $stepData,
        UxonObject $uxon,
        Throwable $exception = null,
    ) : StepNote 
    {
        $note = new StepNote($workbench, $stepData);
        $note->setException($exception);
        $note->importUxonObject($uxon);
        return $note;
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
            'flow_run' => $this->getFlowRunUid(),
            'step_run' => $this->getStepRunUid(),
            'message' => $this->getMessage(),
            'message_code' => $this->getMessageCode(),
            'log_level' => $this->getLogLevel(),
            'exception_flag' => $this->hasException(),
            'exception_message' => $this->getExceptionMessage(),
            'exception_log_id' => $this->getExceptionLogId(),
            'count_reads' => $this->getCountReads(),
            'count_writes' => $this->getCountWrites(),
            'count_creates' => $this->getCountCreates(),
            'count_updates' => $this->getCountUpdates(),
            'count_deletes' => $this->getCountDeletes(),
            'count_errors' => $this->getCountErrors(),
            'count_warnings' => $this->getCountWarnings()
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
     * @inheritdoc
     * @see NoteInterface::getMessageCode()
     */
    public function getMessageCode() : ?string
    {
        return $this->messageCode;
    }

    /**
     * Link a message from the meta model by message code
     * 
     * @uxon-property message
     * @uxon-type metamodel:exface.Core.MESSAGE:CODE
     * 
     * @param string $message
     * @return $this
     * @see NoteInterface::setMessage()
     */
    public function setMessageCode(string $code) : StepNote
    {
        $this->messageCode = $code;
        return $this;
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
     * @see NoteInterface::setException()
     */
    function setException(?Throwable $exception): void
    {
        $this->exceptionFlag = (bool)$exception;
        $this->exceptionMessage = $exception?->getMessage();
        $this->exceptionLogId = $exception?->getId();
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getExceptionMessage()
     */
    function getExceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getExceptionMessage()
     */
    function getExceptionLogId(): ?string
    {
        return $this->exceptionLogId;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::hasException()
     */
    function hasException(): bool
    {
        return $this->exceptionFlag;
    }


    /**
     * @param int|null $count
     * @return $this
     */
    public function setCountReads(?int $count) : StepNote
    {
        $this->countReads = $count;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCountReads() : ?int
    {
        return $this->countReads;
    }

    /**
     * @param int|null $count
     * @return $this
     */
    public function setCountWrites(?int $count) : StepNote
    {
        $this->countWrites = $count;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCountWrites() : ?int
    {
        return $this->countWrites;
    }

    /**
     * @param int|null $count
     * @return $this
     */
    public function setCountCreates(?int $count) : StepNote
    {
        $this->countCreates = $count;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCountCreates() : ?int
    {
        return $this->countCreates;
    }

    /**
     * @param int|null $count
     * @return $this
     */
    public function setCountUpdates(?int $count) : StepNote
    {
        $this->countUpdates = $count;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCountUpdates() : ?int
    {
        return $this->countUpdates;
    }

    /**
     * @param int|null $count
     * @return $this
     */
    public function setCountDeletes(?int $count) : StepNote
    {
        $this->countDeletes = $count;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCountDeletes() : ?int
    {
        return $this->countDeletes;
    }

    /**
     * @param int|null $count
     * @return $this
     */
    public function setCountErrors(?int $count) : StepNote
    {
        $this->countErrors = $count;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCountErrors() : ?int
    {
        return max($this->hasException() ? 1 : 0, $this->countErrors);
    }

    /**
     * @param int|null $count
     * @return $this
     */
    public function setCountWarnings(?int $count) : StepNote
    {
        $this->countWarnings = $count;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCountWarnings() : ?int
    {
        return $this->countWarnings;
    }

    /**
     * Import tracking data from a `CrudCounter`.
     * 
     * @param CrudCounter $counter
     * @return $this
     */
    public function importCrudCounter(CrudCounter $counter) : StepNote
    {
        $this->setCountWrites($counter->getWrites());
        $this->setCountCreates($counter->getCreates());
        $this->setCountReads($counter->getReads());
        $this->setCountUpdates($counter->getUpdates());
        $this->setCountDeletes($counter->getDeletes());
        
        return $this;
    }
}