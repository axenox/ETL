<?php
namespace axenox\ETL\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\CommonLogic\UxonObject;
use axenox\ETL\Interfaces\ETLStepInterface;
use exface\Core\DataTypes\UUIDDataType;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Exceptions\RuntimeException;
use axenox\ETL\Factories\ETLStepFactory;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\Exceptions\ActionExceptionInterface;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use axenox\ETL\Events\Flow\OnBeforeETLStepRun;
use exface\Core\Factories\WidgetFactory;
use axenox\ETL\Common\IncrementalEtlStepResult;
use exface\Core\Interfaces\Actions\iModifyData;

/**
 * 
 * @author andrej.kabachnik
 *
 */
class RunETLFlow extends AbstractActionDeferred implements iCanBeCalledFromCLI, iModifyData
{
    private $stepsLoaded = [];
    
    private $stepsPerFlowUid = [];
    
    private $flowStoppers = [];
    
    private $flowToRun = null;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [$this->getFlowAliases($task)];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(array $flows = []) : \Generator
    {
        foreach ($flows as $alias) {
            yield from $this->runFlow($alias);
        }
    }
    
    /**
     * 
     * @param string $alias
     * @throws null
     * @return \Generator|string[]
     */
    protected function runFlow(string $alias) : \Generator
    {
        $flowRunUid = UUIDDataType::generateSqlOptimizedUuid();
        $indent = '  ';
        
        yield 'Running ETL flow "' . $alias . '" (run-UID ' . $flowRunUid . ').' . PHP_EOL;
        yield PHP_EOL . $indent . 'Execution plan:' . PHP_EOL;
        
        $prevStepResult = null;
        
        $planner = $this->getStepsPlanGenerator($alias, $indent.$indent);
        yield from $planner;
        $plan = $planner->getReturn();
        
        yield PHP_EOL . 'Starting now...' . PHP_EOL . PHP_EOL;
        foreach ($plan as $pos => $step) {
            $nr = $pos+1;
            $prevRunResult = $this->getPreviousResultData($step);
            $logRow = $this->logRunStart($step, $flowRunUid, $nr, $prevRunResult)->getRow(0);
            
            $stepRunUid = $logRow['UID'];
            yield $indent . $nr . '. ' . $step->getName() . ': ';
            if ($step->isDisabled()) {
                yield 'disabled' . PHP_EOL;
            } else {
                $log = '';
                $this->getWorkbench()->eventManager()->addListener(OnBeforeETLStepRun::getEventName(), function(OnBeforeETLStepRun $event) use (&$logRow, $step) {
                    if ($event->getStep() !== $step) {
                        return;
                    }
                    $ds = $this->logRunDebug($event, $logRow);
                    $logRow = $ds->getRow(0);
                });
                try {
                    $generator = $step->run($stepRunUid, $prevStepResult, $prevRunResult);
                    foreach ($generator as $msg) {
                        $msg = $indent . $indent . $msg;
                        $log .= $msg;
                        yield $msg;
                    }
                    $stepResult = $generator->getReturn();
                    $this->logRunSuccess($logRow, $log, $stepResult);
                } catch (\Throwable $e) {
                    if (! $e instanceof ActionExceptionInterface) {
                        $e = new ActionRuntimeError($this, $e->getMessage(), null, $e);
                    }
                    try {
                        $this->logRunError($logRow, $e, $log);
                    } catch (\Throwable $el) {
                        $this->getWorkbench()->getLogger()->logException($el);
                        yield PHP_EOL . $indent .  '✗ Could not save ETL run log: ' . $el->getMessage() . ' in ' . $el->getFile() . ' on line ' . $el->getLine();
                    }
                    if ($this->getStopFlowOnError($step)) {
                        throw $e;
                    } else {
                        yield PHP_EOL . '✗ ERROR: ' . $e->getMessage();
                        yield PHP_EOL . '  See log-ID ' . $e->getId() . ' for details!';
                        $this->getWorkbench()->getLogger()->logException($e);
                    }
                }
            }
            
            $prevStepResult = $stepResult;
        }
        
        yield PHP_EOL . '✓ Finished successfully' . PHP_EOL;
    }
    
    protected function logRunDebug(OnBeforeETLStepRun $event, array $row)
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.step_run');
        try {
            $debugContainer = WidgetFactory::createDebugMessage($this->getWorkbench(), $ds->getMetaObject());
            $widgetJson = $event->getStep()->createDebugWidget($debugContainer)->exportUxonObject()->toJson();
            $row['debug_widget'] = $widgetJson;
            $ds->addRow($row);
            $ds->dataUpdate();
        } catch (\Throwable $e) {
            // Forget the widget if rendering does not work
            $this->getWorkbench()->getLogger()->logException($e);
            $ds->addRow($row);
        }
        return $ds;
    }
    
    protected function logRunSuccess(array $row, string $output, ETLStepResultInterface $result = null) : DataSheetInterface
    {
        $time = DateTimeDataType::now();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.step_run');
        $row['end_time'] = $time;
        $row['result_count'] = $result->countProcessedRows() ?? 0;
        $row['result_uxon'] = $result->__toString();
        $row['success_flag'] = true;
        $row['output'] = $output;
        
        if (($result instanceof IncrementalEtlStepResult) && $result->getIncrementValue() !== null) {
            $row['incremental_flag'] = true;
        } else {
            $row['incremental_flag'] = false;
            $row['incremental_after_run'] = null;
        }
        
        $ds->addRow($row);
        $ds->dataUpdate();
        return $ds;
    }
    
    protected function logRunError(array $row, ExceptionInterface $exception, string $output = '') : DataSheetInterface
    {
        $time = DateTimeDataType::now();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.step_run');
        $row['end_time'] = $time;
        $row['output'] = $output;
        $row['error_flag'] = true;
        $row['error_message'] = $exception->getMessage();
        $row['error_log_id'] = $exception->getId();
        try {
            $widgetJson = $exception->createWidget(UiPageFactory::createEmpty($this->getWorkbench()))->exportUxonObject()->toJson();
            $row['error_widget'] = $widgetJson;
        } catch (\Throwable $e) {
            // Forget the widget if rendering does not work
            $this->getWorkbench()->getLogger()->logException($e);
        }
        $ds->addRow($row);
        $ds->dataUpdate();
        return $ds;
    }
    
    protected function logRunStart(ETLStepInterface $step, string $flowRunUid, int $position, ETLStepResultInterface $lastResult = null) : DataSheetInterface
    {
        $time = DateTimeDataType::now();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.step_run');
        $row = [
            'step' => $this->getStepUid($step),
            'flow' => $this->getFlowUid($step),
            'flow_run' => $flowRunUid,
            'flow_run_pos' => $position,
            'start_time' => $time,
            'timeout_seconds' => $step->getTimeout(),
            'incremental_flag' => $step->isIncremental(),
            'incremental_after_run' => $lastResult === null ? null : $lastResult->getStepRunUid()
        ];
        try {
            $debugContainer = WidgetFactory::createDebugMessage($this->getWorkbench(), $ds->getMetaObject());
            $widgetJson = $step->createDebugWidget($debugContainer)->exportUxonObject()->toJson();
            $row['error_widget'] = $widgetJson;
        } catch (\Throwable $e) {
            // Forget the widget if rendering does not work
            $this->getWorkbench()->getLogger()->logException($e);
        }
        if ($step->isDisabled()) {
            $row['step_disabled_flag'] = true;
            $row['end_time'] = $time;
        }
        $ds->getColumns()->addFromSystemAttributes();
        $ds->addRow($row);
        $ds->dataCreate();
        return $ds;
    }
    
    protected function getStepUid(ETLStepInterface $step) : string
    {
        $uid = array_search($step, $this->stepsLoaded);
        if (! $uid) {
            throw new ActionRuntimeError($this, 'No UID found for ETL step "' . $step->__toString() . '": step not loaded/planned properly?');
        }
        return $uid;
    }
    
    protected function getFlowUid(ETLStepInterface $step) : string
    {
        foreach ($this->stepsPerFlowUid as $uid => $steps) {
            if (! in_array($step, $steps)) {
                $uid = null;
            }
        }
        if (! $uid) {
            throw new ActionRuntimeError($this, 'No flow found for ETL step "' . $step->__toString() . '": step not loaded/planned properly?');
        }
        return $uid;
    }
    
    /**
     * 
     * @param string $flowAlias
     * @throws ActionRuntimeError
     * @return \Generator|ETLStepInterface[]
     */
    protected function getStepsPlanGenerator(string $flowAlias, string $logIndent) : \Generator
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.step');
        $ds->getFilters()->addConditionFromString('flow__alias', $flowAlias, ComparatorDataType::EQUALS);
        $ds->getSorters()->addFromString('level', SortingDirectionsDataType::ASC);
        $ds->getColumns()->addMultiple([
            'required_for_step',
            'UID',
            'name',
            'etl_prototype',
            'etl_config_uxon',
            'from_object',
            'to_object',
            'disabled',
            'flow',
            'stop_flow_on_error',
            'run_after_step'
        ]);
        $ds->dataRead();
        
        if ($ds->isEmpty()) {
            throw new ActionRuntimeError($this, 'ETL flow "' . $flowAlias . '" not found!');
        }
        
        /* 
         * Steps in original reading order with UIDs as keys. Planned steps are moved
         * from this array to $stepsPlanned.
         * @var $stepsToPlan \axenox\ETL\Interfaces\ETLStepInterface[] 
         */
        $stepsToPlan = [];
        
        /* 
         * Steps in execution order with regular numeric keys starting with 0.
         * @var $stepsPlanned \axenox\ETL\Interfaces\ETLStepInterface[] 
         */
        $stepsPlanned = [];
        
        /* 
         * Array with object aliases as keys and subsets of $stepsToPlan still required 
         * to populate each object as values. Once a step is planned it should be removed
         * from each step-array here it was contained in.
         * @var $stepsForObject \axenox\ETL\Interfaces\ETLStepInterface[][] 
         */
        $stepsForObject = [];
        
        /*
         * Array with step UIDs for keys and their immediate follower step UID as value.
         * It contains only those UIDs, that really have an immediate follower!
         * @var $explicitFollowers string[]
         */
        $explicitFollowers = [];
        
        foreach ($ds->getRows() as $row) {
            $toObj = MetaObjectFactory::createFromString($this->getWorkbench(), $row['to_object']);
            if ($row['from_object']) {
                $fromObj = MetaObjectFactory::createFromString($this->getWorkbench(), $row['from_object']);
            } else {
                $fromObj = $toObj;
            }
            
            $step = ETLStepFactory::createFromFile(
                $row['etl_prototype'], 
                $row['name'], 
                $toObj,
                $fromObj,
                UxonObject::fromAnything($row['etl_config_uxon'] ?? [])
            );
            $step->setDisabled(BooleanDataType::cast($row['disabled']));
            $stepsToPlan[$row['UID']] = $step;
            $stepsForObject[$toObj->getAliasWithNamespace()][$row['UID']] = $step;
            $flowUId = $row['flow'];
            
            if ($row['stop_flow_on_error']) {
                $this->flowStoppers[] = $step;
            }
            
            if ($predecessorUid = ($row['run_after_step'] ?? null)) {
                if (array_key_exists($predecessorUid, $explicitFollowers)) {
                    throw new ActionConfigurationError($this, 'Step "' . $row['name'] . '" cannot be run immediately after step id "' . $explicitFollowers . '": another step is scheduled to run at the same time!');
                }
                $explicitFollowers[$predecessorUid] = $row['UID'];
            }
        }
        
        $this->stepsLoaded = $stepsToPlan;
        $this->stepsPerFlowUid[$flowUId] = $stepsToPlan;
        
        $stepsCnt = count($stepsToPlan);
        for ($i = 0; $i < $stepsCnt; $i++) {
            foreach ($stepsToPlan as $stepUid => $step) {
                // If the step was already planned, skip it
                if (in_array($step, $stepsPlanned)) {
                    continue;
                }
                // If the step is an immediate follower of another step - skip it as it
                // will be handled when that other step (being followed) is planned.
                if (in_array($stepUid, $explicitFollowers)) {
                    continue;
                }
                // See if the steps from-object still has non-planned required steps
                $stepsRequired = $step->getFromObject() ? ($stepsForObject[$step->getFromObject()->getAliasWithNamespace()] ?? []) : [];
                // If not, enqueue the step
                if (empty($stepsRequired) || (count($stepsRequired) === 1 && $stepsRequired[$stepUid] === $step)) {
                    $stepsPlanned[] = $step;
                    yield $logIndent . count($stepsPlanned) . '. ' . $step->getName() . PHP_EOL;
                    // Remove it from all object requirements
                    foreach ($stepsForObject as $o => $reqs) {
                        if (array_key_exists($stepUid, $reqs)) {
                            unset($stepsForObject[$o][$stepUid]);
                        }
                    }
                    // Remove it from the steps to plan to reduce future iterations
                    unset($stepsToPlan[$stepUid]);
                    
                    // See if the step has an immediate follower
                    $followerUid = $explicitFollowers[$stepUid] ?? null;
                    while ($followerUid !== null) {
                        // If so, plan the follow-up step now
                        $followerStep = $stepsToPlan[$followerUid];
                        $stepsPlanned[] = $followerStep;
                        yield $logIndent . count($stepsPlanned) . '. ' . $followerStep->getName() . ' - as immediate follower of (' . (count($stepsPlanned)-1) . '.)' . PHP_EOL;
                        unset($stepsToPlan[$followerUid]);
                        // Remove the follow-up from object requirements
                        foreach ($stepsForObject as $o => $reqs) {
                            if (array_key_exists($followerUid, $reqs)) {
                                unset($stepsForObject[$o][$followerUid]);
                            }
                        }
                        // See if the follow-up has a follow-up and, if so, stay in the WHILE
                        // to handle it - otherwise proceed regularly.
                        $followerUid = $explicitFollowers[$followerUid] ?? null;
                    }
                    
                    // Leave the inner FOREACH and start over from the beginning of the
                    // remaining steps to plan.
                    break;
                }
            }
        }
        
        if (count($stepsPlanned) !== $stepsCnt) {
            yield $logIndent . '✗ Cannot order remaining steps according to their dependencies:' . PHP_EOL;
            foreach ($stepsToPlan as $step) {
                yield $logIndent . '  - Step "' . $step->getName() . '" requires ' . ($step->getFromObject() ? 'object "' . $step->getFromObject()->getAliasWithNamespace() . '"' : 'nothing') . ' with:' . PHP_EOL;
                $stepsRequired = $step->getFromObject() ? ($stepsForObject[$step->getFromObject()->getAliasWithNamespace()] ?? []) : [];
                foreach ($stepsRequired as $req) {
                    yield $logIndent . $logIndent . '- "' . $req->getName() . '"' . PHP_EOL;
                }
            }
            throw new RuntimeException('Cannot determine execution order for ETL steps! Circular dependency?');
        }
        
        return $stepsPlanned;
    }
    
    protected function getFlowAliases(TaskInterface $task) : array
    {
        switch (true) {
            case $this->getFlowAlias():
                return $this->getFlowAlias();
            case $task->hasParameter('flow'):
                return explode(',', $task->getParameter('flow'));
            default:
                $inputData = $this->getInputDataSheet($task);
                if ($inputData->getMetaObject()->is('axenox.ETL.flow') && $col = $inputData->getColumns()->get('host')) {
                    return $col->getValues();
                }
        }
        throw new ActionInputMissingError($this, 'No ETL flow to run: please provide `flow` parameter or input data based on the flow object (axenox.ETL.flow)!');
    }
    
    /**
     * 
     * @param ETLStepInterface $step
     * @return string|NULL
     */
    protected function getPreviousResultData(ETLStepInterface $step) : ?ETLStepResultInterface
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.step_run');
        $sheet->getFilters()->addConditionFromString('step', $this->getStepUid($step), ComparatorDataType::EQUALS);
        $sheet->getFilters()->addConditionFromString('success_flag', 1, ComparatorDataType::EQUALS);
        $sheet->getFilters()->addConditionFromString('invalidated_flag', 0, ComparatorDataType::EQUALS);
        $sheet->getSorters()->addFromString('start_time', SortingDirectionsDataType::DESC);
        $sheet->getColumns()->addMultiple([
            'UID',
            'result_uxon'
        ]);
        $sheet->dataRead(1);
        if (! $sheet->isEmpty()) {
            return $step::parseResult($sheet->getCellValue('UID', 0), $sheet->getCellValue('result_uxon', 0));
        } else {
            return null;
        }
    }
    
    protected function getStopFlowOnError(ETLStepInterface $step) : bool
    {
        return in_array($step, $this->flowStoppers);
    }
    
    public function getCliArguments(): array
    {
        return [
            (new ServiceParameter($this))
            ->setName('flow')
            ->setDescription('Namespaced alias of the ETL flow to run.')
        ];
    }

    public function getCliOptions(): array
    {
        return [];
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getFlowAlias() : ?string
    {
        return $this->flowToRun;
    }
    
    /**
     * Alias of the flow to run.
     * 
     * If not set, the flow alias will be determined from the task provided:
     * - task parameter `flow` or
     * - values from the `alias` column in the input data sheet
     * 
     * @uxon-property flow
     * @uxon-type string
     * 
     * @param string $value
     * @return RunETLFlow
     */
    public function setFlowAlias(string $value) : RunETLFlow
    {
        $this->flowToRun = $value;
        return $this;
    }
}