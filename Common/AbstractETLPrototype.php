<?php
namespace axenox\ETL\Common;

use axenox\ETL\Common\Traits\ITakeStepNotesTrait;
use axenox\ETL\Events\Flow\OnAfterETLStepRun;
use axenox\ETL\Interfaces\NoteInterface;
use exface\Core\CommonLogic\DataSheets\CrudCounter;
use exface\Core\CommonLogic\DataSheets\DataSheetTracker;
use exface\Core\CommonLogic\Debugger\LogBooks\FlowStepLogBook;
use exface\Core\DataTypes\MessageTypeDataType;
use exface\Core\Exceptions\DataSheets\DataCheckFailedErrorMultiple;
use exface\Core\Exceptions\DataTrackerException;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\MessageFactory;
use exface\Core\Interfaces\DataSheets\DataColumnInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\TranslationInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use axenox\ETL\Interfaces\ETLStepInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use exface\Core\Widgets\DebugMessage;

abstract class AbstractETLPrototype implements ETLStepInterface
{
    use ImportUxonObjectTrait;
    use ITakeStepNotesTrait;
    
    const PH_PARAMETER_PREFIX = '~parameter:';
    const PH_LAST_RUN_PREFIX = 'last_run_';
    const PH_LAST_RUN_UID = 'last_run_uid';
    const PH_FLOW_RUN_UID = 'flow_run_uid';
    const PH_STEP_RUN_UID = 'step_run_uid';
    const IF_DUPLICATES_ERROR = 'error';
    const IF_DUPLICATES_DISABLE_TRACKER = 'disable_tracker';
    const IF_DUPLICATES_IGNORE = 'ignore';
    
    private $workbench = null;
    
    private $uxon = null;
    
    private $stepRunUidAttributeAlias = null;
    
    private $flowRunUidAttribtueAlias = null;
    
    private $fromObject = null;
    
    private $toObject = null;
    
    private $name = null;
    
    private $disabled = null;
    
    private $timeout = 30;
    private ?UxonObject $toDataChecksUxon = null;
    private ?UxonObject $fromDataChecksUxon = null;
    private CrudCounter $crudCounter;
    private array $logBooks = [];
    private ?DataSheetTracker $dataTracker = null;
    private array $trackedAliases = [];
    private string $ifDuplicatesDetected = self::IF_DUPLICATES_ERROR;

    public function __construct(string $name, MetaObjectInterface $toObject, MetaObjectInterface $fromObject = null, UxonObject $uxon = null)
    {
        $this->workbench = $toObject->getWorkbench();
        $this->uxon = $uxon;
        $this->fromObject = $fromObject;
        $this->toObject = $toObject;
        $this->name = $name;
        $this->crudCounter = new CrudCounter($this->workbench, 1);
        
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return $this->uxon ?? new UxonObject();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::getName()
     */
    public function getName() : string
    {
        return $this->name;
    }
    
    /**
     * 
     * @param string $name
     * @return string|UxonObject
     */
    protected function getConfigProperty(string $name)
    {
        return $this->uxon->getProperty($name);
    }
    
    /**
     * 
     * @param string $name
     * @return bool
     */
    protected function hasConfigProperty(string $name) : bool
    {
        return $this->uxon->hasProperty($name);
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getStepRunUidAttributeAlias() : ?string
    {
        return $this->stepRunUidAttributeAlias;
    }
    
    /**
     * Alias of the attribute of the to-object where the UID of every step run is to be saved
     * 
     * @uxon-property step_run_uid_attribute
     * @uxon-type metamodel:attribute
     * 
     * @param string $value
     * @return AbstractETLPrototype
     */
    protected function setStepRunUidAttribute(string $value) : AbstractETLPrototype
    {
        $this->stepRunUidAttributeAlias = $value;
        return $this;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getFlowRunUidAttributeAlias() : ?string
    {
        return $this->flowRunUidAttribtueAlias;
    }
    
    /**
     * Alias of the attribute of the to-object where the UID of the flow run is to be saved (same value for all steps
     * in a flow)
     * 
     * @uxon-property flow_run_uid_attribute
     * @uxon-type metamodel:attribute
     * 
     * @param string $value
     * @return AbstractETLPrototype
     */
    protected function setFlowRunUidAttribute(string $value) : AbstractETLPrototype
    {
        $this->flowRunUidAttribtueAlias = $value;
        return $this;
    }

    /**
     * @return string
     */
    protected function getIfDuplicatesDetected() : string
    {
        return $this->ifDuplicatesDetected;
    }

    /**
     * Configure how this step should react, if it detects duplicate entries in its input data.
     * 
     * @uxon-property if_duplicates_detected
     * @uxon-type [error,disable_tracker,ignore]
     * @uxon-template error
     * 
     * @param string $behavior
     * @return $this
     */
    protected function setIfDuplicatesDetected(string $behavior) : AbstractETLPrototype
    {
        $this->ifDuplicatesDetected = $behavior;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::getFromObject()
     */
    public function getFromObject() : MetaObjectInterface
    {
        return $this->fromObject;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::getToObject()
     */
    public function getToObject() : MetaObjectInterface
    {
        return $this->toObject;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::isDisabled()
     */
    public function isDisabled() : bool
    {
        return $this->disabled;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::setDisabled()
     */
    public function setDisabled(bool $value) : ETLStepInterface
    {
        $this->disabled = $value;
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    public function __toString() : string
    {
        return $this->getName();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::getTimeout()
     */
    public function getTimeout() : int
    {
        return $this->timeout;
    }
    
    /**
     * Number of seconds the step is allowed to run at maximum.
     * 
     * @uxon-property timeout
     * @uxon-type integer
     * @uxon-default 30
     * 
     * @param int $seconds
     * @return ETLStepInterface
     */
    public function setTimeout(int $seconds) : ETLStepInterface
    {
        $this->timeout = $seconds;
        return $this;
    }
    
    /**
     * Returns an array with names and values for placeholders that can be used in the steps config.
     * 
     * @param string $stepRunUid
     * @param ETLStepResultInterface $lastResult
     * @return string[]
     */
    protected function getPlaceholders(ETLStepDataInterface $stepData) : array
    {
    	// map identifier to placeholders
        $phs = [
        	self::PH_FLOW_RUN_UID => $stepData->getFlowRunUid(),
        	self::PH_STEP_RUN_UID => $stepData->getStepRunUid()
        ];
        
        $lastResult = $stepData->getLastResult();
        if ($lastResult === null) {
            $lastResult = static::parseResult('');
        }
        
        $phs[self::PH_LAST_RUN_UID] = $lastResult->getStepRunUid();
        foreach ($lastResult->exportUxonObject(true)->toArray() as $ph => $val) {
            if (is_scalar($val) || $val === null) {
                $phs[self::PH_LAST_RUN_PREFIX . $ph] = $val ?? '';
            }
        }
        
        // map query parameter to placeholders
        $task = $stepData->getTask();
        foreach ($task->getParameters() as $name => $value) {
        	$phs[self::PH_PARAMETER_PREFIX . $name] = $value;
        }
        return $phs;
    }

    /**
     * Performs all data checks defined in a given UXON.
     *
     * NOTE: All rows that fail at least one data check will be marked as invalid in the `is_valid_attribute` column on
     * `dataSheet`. You can use this information to ignore them in future processing. If `stop_on_failed_check` is
     * TRUE, the step will be terminated, if at least one row failed at least one data check. In either case, all
     * checks will be performed first.
     *
     * @param DataSheetInterface   $dataSheet
     * @param UxonObject|null      $uxon
     * @param string               $uxonProperty
     * @param ETLStepDataInterface $stepData
     * @param FlowStepLogBook      $logBook
     * @return void
     */
    protected function performDataChecks(
        DataSheetInterface   $dataSheet,
        ?UxonObject          $uxon,
        string               $uxonProperty,
        ETLStepDataInterface $stepData,
        FlowStepLogBook      $logBook) : void
    {
        if($uxon === null || $uxon->isEmpty()) {
            $logBook->addLine('No data checks defined in `' . $uxonProperty . '`');
            return;
        }
        
        $logBook->addLine('Applying ' . $uxon->countProperties() . ' data checks from `' . $uxonProperty . '`');
        $logBook->addIndent(1);
        
        $errors = null;
        $stopOnError = false;
        $badDataBase = $dataSheet->copy()->removeRows();
        $badData = $badDataBase->copy();
        
        foreach ($uxon as $dataCheckUxon) {
            $check = new DataCheckWithStepNote(
                $this->getWorkbench(), 
                $dataCheckUxon,
                null,
                $this
            );
            
            if(!$check->isApplicable($dataSheet)) {
                continue;
            }

            $badDataForCheck = $badDataBase->copy();
            
            try {
                $check->check($dataSheet, $logBook, $stepData, $badDataForCheck, false);
                $check->getNoteOnSuccess($stepData)?->takeNote();
            } catch (DataCheckFailedErrorMultiple $e) {
                $errors = $errors ?? new DataCheckFailedErrorMultiple('', null, null, $this->getWorkbench()->getCoreApp()->getTranslator());
                $errors->merge($e);

                $stopOnError |= $check->getStopOnCheckFailed();
                
                $badData->addRows($badDataForCheck->getRows());
                $errorRowNrs = $errors->getAllRowNumbers();

                $failToFind = [];
                $baseData = $this->getBaseData(
                    $badDataForCheck, 
                    $failToFind
                );
                
                $failToFindWithRowNrs = [];
                foreach ($failToFind as $rowNr => $data) {
                    $index = $this->toDisplayRowNumber($rowNr, true);
                    $rowNr = $this->toDisplayRowNumber($errorRowNrs[$index]);
                    $failToFindWithRowNrs[$rowNr] = $data;
                }
                
                $check->getNoteOnFailure(
                    $stepData, 
                    $e
                )->enrichWithAffectedData(
                    $baseData,
                    $failToFindWithRowNrs
                )->setCountErrors(
                    count($e->getAllErrors())
                )->takeNote();
            }
        }
        
        if($badData->countRows() > 0) {
            $logBook->addDataSheet($uxonProperty . ': Bad Data', $badData);
        }

        if($dataSheet->countRows() === 0) {
            $msg = 'All from-rows removed by failed data checks. **Exiting step**.';
            $logBook->addLine($msg);
            $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
            throw new RuntimeException('All input rows failed to write or were skipped due to errors!', '81VV7ZF');
        }
        
        if($errors === null) {
            $logBook->addLine('Data PASSED all checks.');
        } else if ($stopOnError) {
            $logBook->addIndent(-1);
            $logBook->addLine('Terminating step, because one or more data checks FAILED.');
            $this->getCrudCounter()->stop();
            $this->getWorkbench()->eventManager()->dispatch(new OnAfterETLStepRun($this, $logBook));
            
            throw $errors;
        }
        $logBook->addIndent(-1);
    }

    /**
     * Applies a specified transform function row by row to the data sheet.
     * Whenever a row encounters an error, the error will be logged and the
     * row discarded.
     *
     * @param callable             $transformer
     * @param DataSheetInterface   $dataSheet
     * @param ETLStepDataInterface $stepData
     * @param FlowStepLogBook      $logBook
     * @param array                $visibility
     * @return DataSheetInterface
     */
    protected function applyTransformRowByRow(
        callable $transformer,
        DataSheetInterface $dataSheet,
        ETLStepDataInterface $stepData,
        FlowStepLogBook $logBook,
        array $visibility
    ) : DataSheetInterface
    {
        $saveSheet = $dataSheet;
        $resultSheet = null;
        $translator = $this->getTranslator();

        $affectedBaseData = [];
        $affectedCurrentData = [];

        foreach ($dataSheet->getRows() as $i => $row) {
            $saveSheet = $saveSheet->copy();
            $saveSheet->removeRows();
            $saveSheet->addRow($row, false, false);
            try {
                // Get the resulting data sheet of that single line and add it to the global
                // result data
                $rowResultSheet = call_user_func($transformer, $i, $saveSheet);
                //$rowResultSheet = $this->$transformFuncName($saveSheet, $stepData, $logBook);
                if ($resultSheet === null) {
                    $resultSheet = $rowResultSheet;
                } else {
                    foreach ($rowResultSheet->getRows() as $resultRow) {
                        $resultSheet->addRow($resultRow, false, false);
                    }
                }
            } catch (\Throwable $e) {
                // If anything goes wrong, just continue with the next row.
                $this->getWorkbench()->getLogger()->logException($e, LoggerInterface::ERROR);

                $failedToFind = [];
                $baseData = $this->getBaseData(
                    $saveSheet, 
                    $failedToFind
                );
                
                if(!empty($baseData)) {
                    $rowNo = array_key_first($baseData);
                    $affectedBaseData[$rowNo] = $baseData[$rowNo];
                } else {
                    $rowNo = $this->toDisplayRowNumber($i);
                    $affectedCurrentData[$rowNo] = $failedToFind[0];
                }

                StepNote::fromException(
                    $stepData,
                    $e,
                    $translator->translate('NOTE.ROWS_SKIPPED', ['%number%' => $rowNo], 1),
                    false,
                    $visibility
                )->takeNote();
            }
        }

        if(!empty($affectedBaseData) || !empty($affectedCurrentData)) {
            StepNote::fromMessageCode(
                $stepData,
                '82131JM',
            )->enrichWithAffectedData(
                $affectedBaseData,
                $affectedCurrentData,
                false
            )->takeNote();
        }

        if($resultSheet === null) {
            $resultSheet = $dataSheet->copy()->removeRows();
        }

        return $resultSheet;
    }

    /**
     * Define a set of data checks to performed on the data RECEIVED by this step. Use the property
     * `stop_on_failed_check` to control, whether a failed check should halt the procedure.
     *
     * NOTE: You can configure per step, whether it should generate a step note on success and/or failure.
     *
     * @uxon-property from_data_checks
     * @uxon-type \axenox\etl\Common\DataCheckWithStepNote[]
     * @uxon-template [{"note_on_failure": {"message":"", "message_type":"warning"},"conditions":[{"expression":"","comparator":"==","value":""}]}]
     *
     * @param UxonObject $uxon
     * @return $this
     */
    public function setFromDataChecks(UxonObject $uxon) : AbstractETLPrototype
    {
        $this->fromDataChecksUxon = $uxon;
        return $this;
    }

    /**
     * IDEA move to a DataSheetStepTrait? to/from- DataChecks only make sense for data sheets, not for SQL steps.
     * @return UxonObject|null
     */
    public function getFromDataChecksUxon() : ?UxonObject
    {
        return $this->fromDataChecksUxon;
    }

    /**
     * Define a set of data checks to performed on the data PRODUCED by this step.
     * The checks will be applied just before the result data is committed. Use the property
     * `stop_on_failed_check` to control, whether a failed check should halt the procedure.
     *
     * NOTE: You can configure per step, whether it should generate a step note on success and/or failure.
     *
     * @uxon-property to_data_checks
     * @uxon-type \axenox\etl\Common\DataCheckWithStepNote[]
     * @uxon-template [{"note_on_failure": {"message":"", "message_type":"warning"},"conditions":[{"expression":"","comparator":"==","value":""}]}]
     *
     * @param UxonObject $uxon
     * @return $this
     */
    public function setToDataChecks(UxonObject $uxon) : AbstractETLPrototype
    {
        $this->toDataChecksUxon = $uxon;
        return $this;
    }

    /**
     * @return UxonObject|null
     */
    public function getToDataChecksUxon() : ?UxonObject
    {
        return $this->toDataChecksUxon;
    }

    /**
     * @return CrudCounter
     */
    public function getCrudCounter() : CrudCounter
    {
        return $this->crudCounter;
    }

    /**
     * @param ETLStepDataInterface $stepData
     * @return FlowStepLogBook
     */
    protected function getLogBook(ETLStepDataInterface $stepData) : FlowStepLogBook
    {
        foreach ($this->logBooks as $logBook) {
            if($logBook->getStepData() === $stepData) {
                return $logBook;
            }
        }
        
        $logBook = new FlowStepLogBook('Step: "' . $this->getName() . '"', $this, $stepData);
        $this->logBooks[] = $logBook;
        
        return $logBook;
    }

    /**
     * @inheritdoc 
     * @see iCanGenerateDebugWidgets::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget, ?ETLStepDataInterface $stepData = null)
    {
        if(empty($this->logBooks)) {
            return $debug_widget;
        }
        if ($stepData === null) {
            return $this->logBooks[0]->createDebugWidget($debug_widget);
        }
        return $this->getLogBook($stepData)->createDebugWidget($debug_widget);
    }

    /**
     * Converts a data sheet row number to a display row number that allows humans
     * to identify this row in their input data.
     *
     * For example, in an EXCEL-Import, where the spreadsheet has a title row, the row number
     * will be shifted up by 2:
     *
     * - +1, because EXCEL starts counting from 1.
     * - +1, to compensate for the title row.
     *
     * Row 0 would become row 2 and so on.
     *
     * NOTE: This function does not find the index your row had in the original data set!
     * Use `findDisplayRowNumbers(array)` to find out what index a row had in the from-data.
     *
     * @param int  $dataSheetRowIdx
     * @param bool $inverse
     * If TRUE, the input will be converted back to an array index.
     * @return int
     */
    public function toDisplayRowNumber(int $dataSheetRowIdx, bool $inverse = false) : int
    {
        return $dataSheetRowIdx;
    }

    /**
     * Begin tracking transform for the data provided. 
     * 
     * @param array                $columns
     * @param ETLStepDataInterface $stepData
     * @param FlowStepLogBook      $logBook
     * @return bool
     */
    protected function startTrackingData(array $columns, ETLStepDataInterface $stepData, FlowStepLogBook $logBook) : bool
    {
        if($this->dataTracker !== null || empty($columns)) {
            return false;
        }

        try {
            $this->dataTracker = new DataSheetTracker($columns, false);
        } catch (DataTrackerException $exception) {
            $badData = $exception->getBadData();
            $badData = array_combine(
                array_map([$this, 'toDisplayRowNumber'], array_keys($badData)),
                $badData
            );
            
            if($this->ifDuplicatesDetected == self::IF_DUPLICATES_IGNORE) {
                $exception->setAlias('81YKZHB');
            }
            
            StepNote::fromException(
                $stepData,
                $exception,
                '',
                false
            )->enrichWithAffectedData(
                $badData,
                [],
            )->setMessageType(
                MessageTypeDataType::WARNING
            )->takeNote();


            switch ($this->ifDuplicatesDetected) {
                case self::IF_DUPLICATES_ERROR:
                    throw new RuntimeException('Process failed.', '81YKTKG');
                case self::IF_DUPLICATES_IGNORE:
                    $this->dataTracker = new DataSheetTracker($columns, true);
                    $logBook->addLine('**WARNING** - Data tracking will be unreliable: ' . $exception->getMessage());
                    break;
                default:
                    $logBook->addLine('**WARNING** - Data tracking not possible: ' . $exception->getMessage());
            }
        }
        
        return true;
    }

    /**
     * @param DataColumnInterface[] $fromColumns
     * @param DataColumnInterface[] $toColumns
     * @param int   $preferredVersion
     * @return void
     * @see DataSheetTracker::recordDataTransform()
     */
    protected function recordTransform(array $fromColumns, array $toColumns, int $preferredVersion = -1) : void
    {
        $this->dataTracker?->recordDataTransform($fromColumns, $toColumns, $preferredVersion);
    }

    /**
     * @param DataSheetInterface $baseData
     * @param array              $failedToFind
     * @return array
     * @see DataSheetTracker::getBaseDataForSheet()
     */
    protected function getBaseData(DataSheetInterface $baseData, array &$failedToFind) : array
    {
        if($this->dataTracker === null) {
            $failedToFind = $baseData->getRows();
            return [];
        }
        
        return $this->dataTracker->getBaseDataForSheet(
            $baseData,
            $failedToFind,
            [$this, 'toDisplayRowNumber']
        );
    }
    
    /**
     * @return DataSheetTracker|null
     */
    protected function getDataTracker() : ?DataSheetTracker
    {
        return $this->dataTracker;
    }

    /**
     * @return array
     * @see DataSheetTracker::getTrackedAliases()
     */
    protected function getTrackedAliases() : array
    {
        return $this->dataTracker ? $this->dataTracker->getTrackedAliases() : [];
    }

    /**
     * @return TranslationInterface
     */
    protected function getTranslator() : TranslationInterface
    {
        return $this->getWorkbench()->getApp('axenox.ETL')->getTranslator();
    }
}