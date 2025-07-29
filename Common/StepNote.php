<?php

namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\NoteInterface;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\DataTypes\MessageTypeDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\Exceptions\DataSheetValueExceptionInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
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
    
    private StepNoteTaker $noteTaker;
    private string $flowRunUid;
    private string $stepRunUid;
    private string $message;
    private ?string $messageCode = null;
    private ?string $messageType = null;
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
    private array $contextData = [];
    private array $visibleForUserRoles = NoteInterface::VISIBLE_FOR_EVERYONE;

    /**
     * @param StepNoteTaker         $noteTaker
     * @param string                $message
     * @param Throwable|string|null $messageTypeOrException
     * @param UxonObject|null       $uxon
     */
    public function __construct(
        StepNoteTaker    $noteTaker,
        string           $message,
        Throwable|string $messageTypeOrException = null,
        ?UxonObject      $uxon = null,
    )
    {
        $stepData = $noteTaker->getStepData();
        $hasException = $messageTypeOrException !== null && !is_string($messageTypeOrException);
        
        $this->noteTaker = $noteTaker;
        $this->flowRunUid = $stepData->getFlowRunUid();
        $this->stepRunUid = $stepData->getStepRunUid();
        $this->message = $message;

        if(!$hasException) {
            $this->messageType = $messageTypeOrException ?? 'info';
        } else {
            $this->enrichWithException($messageTypeOrException);
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
        $this->noteTaker->takeNote($this);
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getNoteData()
     */
    public function getNoteData(): array
    {
        return [
            'flow_run' => $this->getFlowRunUid(),
            'step_run' => $this->getStepRunUid(),
            'message' => $this->getMessage(),
            'message_code' => $this->getMessageCode(),
            'message_type' => $this->getMessageType(),
            'exception_flag' => $this->hasException(),
            'exception_message' => $this->getExceptionMessage(),
            'exception_log_id' => $this->getExceptionLogId(),
            'count_reads' => $this->getCountReads(),
            'count_writes' => $this->getCountWrites(),
            'count_creates' => $this->getCountCreates(),
            'count_updates' => $this->getCountUpdates(),
            'count_deletes' => $this->getCountDeletes(),
            'count_errors' => $this->getCountErrors(),
            'count_warnings' => $this->getCountWarnings(),
            'context_data' => $this->getContextData(),
            'visible_for_user_roles' => implode(',',$this->getVisibleForUserRoles())
        ];
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
    public function getMessage(): string
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
     * Link a message from the metamodel by message code
     *
     * @uxon-property message_code
     * @uxon-type metamodel:exface.Core.MESSAGE:CODE
     *
     * @param string $code
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
     * @uxon-property message_type
     * @uxon-type [hint,info,success,question,warning,error]
     * 
     * @param string $messageType
     * @return NoteInterface
     * @see NoteInterface::setMessageType()
     */
    public function setMessageType(string $messageType): NoteInterface
    {
        $this->messageType = MessageTypeDataType::cast($messageType);
        return $this;
    }

    /**
     * @deprecated 
     */
    public function setLogLevel(string $logLevel): NoteInterface
    {
        switch ($logLevel) {
            case 'debug':
            case 'notice':
                $logLevel = MessageTypeDataType::INFO;
                break;
            case 'emergency':
            case 'critical':
            case 'alert':
                $logLevel = MessageTypeDataType::ERROR;
                break;
        }
        return $this->setMessageType($logLevel);
    }

    /**
     * @inheritdoc
     * @see NoteInterface::getMessageType()
     */
    public function getMessageType(): ?string
    {
        return $this->messageType;
    }

    /**
     * @inheritdoc 
     * @see NoteInterface::setException()
     */
    public function setException(?Throwable $exception): void
    {
        $this->exceptionFlag = (bool)$exception;
        $this->exceptionMessage = $exception?->getMessage();
        $this->exceptionLogId = $exception?->getId();
        if(empty($this->messageCode) && $exception instanceof ExceptionInterface) {
            $this->messageCode = $exception->getAlias();
        }
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
     * @see NoteInterface::getExceptionMessage()
     */
    public function getExceptionLogId(): ?string
    {
        return $this->exceptionLogId;
    }

    /**
     * @inheritdoc
     * @see NoteInterface::hasException()
     */
    public function hasException(): bool
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

    /**
     * @inheritDoc
     */
    public function addContextData(array $contextData): NoteInterface
    {
        $this->contextData = array_merge($this->contextData, $contextData);
        return $this;
    }

    /**
     * @inerhitDoc 
     */
    public function getContextData(): array
    {
        return $this->contextData;
    }

    /**
     * Add a set of rows and row numbers as context for this note. 
     * 
     * NOTE: While it is implied that the row numbers relate to the rows, this relationship
     * does not have to be direct. For example: If you wanted to log 100 faulty rows of data, you might
     * add only 10 sample rows, but the row numbers of all 100, to save on stored data volume.
     * 
     * @param array  $rows
     * @param array  $rowNumbers
     * @param string $key
     *  The data provided will be stored, using this key. Repeated calls of this method with the same key
     *  will overwrite any data stored under that key.
     * @return $this
     */
    protected function addRowsAsContext(
        array $rows = [],
        array $rowNumbers = [],
        string $key = 'affected_rows'
    ) : StepNote
    {
        $this->addContextData(
            [$key => [
                'data' => $rows,
                'row_numbers' => $rowNumbers
            ]]
        );
        
        return $this;
    }

    /**
     * @inheritDoc
     * @see NoteInterface::enrichWithAffectedData()
     */
    public function enrichWithAffectedData(
        array $baseData,
        array $currentData,
        bool $prepend = true
    ) : StepNote
    {
        $baseRowNrs = array_keys($baseData);
        $currentRowNrs = array_keys($currentData);

        $msgBaseRowNrs = implode(', ', $baseRowNrs);
        $msgCurrentRowNrs = implode('*, ', $currentRowNrs) . (empty($currentRowNrs) ?  '' : '*');
        $separator = empty($baseRowNrs) || empty($currentRowNrs) ? '' : ', ';
        $msgAllRows = '(' . $msgBaseRowNrs . $separator . $msgCurrentRowNrs . ')';
        $msg = $this->noteTaker->getTranslator()->translate(
            'NOTE.ROWS_SKIPPED',
            ['%number%' => $msgAllRows],
            count($baseData) + count($currentData)
        );

        $msg = StringDataType::endSentence($msg);
        
        if($prepend) {
            $this->setMessage($msg . ' ' . $this->getMessage());
        } else {
            $this->setMessage(StringDataType::endSentence($this->getMessage()) . ' ' . $msg);
        }

        $baseData = array_slice($baseData, 0, 10, true);
        $currentData = array_slice($baseData, 0, 10, true);

        $this->addRowsAsContext($baseData, $baseRowNrs);
        $this->addRowsAsContext($currentData, $currentRowNrs, 'affected_rows_current');
        
        return $this;
    }

    /**
     * @inheritDoc
     * @see NoteInterface::enrichWithException()
     */
    public function enrichWithException(
        \Throwable $exception,
        string $preamble = null,
        bool $showRowNumbers = true
    ) : StepNote
    {
        if ($exception instanceof ExceptionInterface) {
            $logLevel = $exception->getLogLevel();
            if (LogLevelDataType::compareLogLevels($logLevel, LoggerInterface::ERROR) < 0) {
                $msgType = MessageTypeDataType::WARNING;
            } else {
                $msgType = MessageTypeDataType::ERROR;
            }
            if ($showRowNumbers === false && $exception instanceof DataSheetValueExceptionInterface) {
                $text = $exception->getMessageTitleWithoutLocation();
            } else {
                $text = $exception->getMessageModel($this->noteTaker->getWorkbench())->getTitle();
            }
            $code = $exception->getAlias();
        } else {
            $msgType = MessageTypeDataType::ERROR;
            $text = $exception->getMessage();
            $code = null;
        }

        if ($preamble !== null) {
            $text = StringDataType::endSentence($preamble) . ' ' . $text;
        }

        $this->setMessage($text);
        $this->setMessageType($msgType);
        $this->setException($exception);

        if($code !== null) {
            $this->setMessageCode($code);
        }

        return $this;
    }


    /**
     * @inheritDoc
     * 
     * @uxon-property visible_for_user_roles
     * @uxon-type metamodel:exface.Core.USER_ROLE:ALIAS[]
     * @uxon-template ["SUPERUSER"]
     * 
     * @param array $roles
     * @return NoteInterface
     */
    function setVisibleUserRoles(array|string $roles) : NoteInterface
    {
        if(is_string($roles)) {
            $roles = [$roles];
        }
        
        $this->visibleForUserRoles = $roles;
        return $this;
    }

    /**
     * @inheritDoc
     */
    function getVisibleForUserRoles(): array
    {
        return $this->visibleForUserRoles;
    }
}