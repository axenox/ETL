<?php
namespace axenox\ETL\Common;

use axenox\ETL\Interfaces\ETLStepDataInterface;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use exface\Core\CommonLogic\Debugger\Profiler;
use exface\Core\Interfaces\Tasks\TaskInterface;

class ETLStepData implements ETLStepDataInterface
{
	private TaskInterface $task;
	
	private string $flowRunUid;
	
	private ?string $stepRunUid;
	
	private ?ETLStepResultInterface $previousStepResult;
	
	private ?ETLStepResultInterface $lastResult;
    
    private StepNoteTaker $noteTaker;
    private Profiler $profiler;

    /**
     *
     * @param TaskInterface               $task
     * @param string                      $flowRunUid
     * @param string|null                 $stepRunUid
     * @param ETLStepResultInterface|null $previousStepResult
     * @param ETLStepResultInterface|null $lastResult
     * @param Profiler|null               $profiler
     */
    public function __construct(
		TaskInterface $task,
		string $flowRunUid,
		string $stepRunUid = null,
        ETLStepResultInterface $previousStepResult = null,
        ETLStepResultInterface $lastResult = null,
        Profiler $profiler = null
    ) 
    {
		$this->task = $task;
		$this->flowRunUid = $flowRunUid;
        $this->stepRunUid = $stepRunUid;
        $this->previousStepResult = $previousStepResult;
        $this->lastResult = $lastResult;
        $this->noteTaker = new StepNoteTaker($task->getWorkbench());
        $this->profiler = $profiler ?? new Profiler($task->getWorkbench());
    }
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\ETL\Interfaces\ETLStepDataInterface::getFlowRunUid()
	 */
	public function getFlowRunUid() : string 
	{
		return $this->flowRunUid;
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\ETL\Interfaces\ETLStepDataInterface::getStepRunUid()
	 */
	public function getStepRunUid() : string
	{		
		return $this->stepRunUid;
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\ETL\Interfaces\ETLStepDataInterface::setStepRunUid()
	 */
	public function setStepRunUid(string $value) : ETLStepDataInterface
	{
	    $this->stepRunUid = $value;
	    return $this;
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\ETL\Interfaces\ETLStepDataInterface::getPreviousResult()
	 */
	public function getPreviousResult( ): ?ETLStepResultInterface
	{
		return $this->previousStepResult;
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\ETL\Interfaces\ETLStepDataInterface::getLastResult()
	 */
	public function getLastResult() : ?ETLStepResultInterface
	{
		return $this->lastResult;
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\ETL\Interfaces\ETLStepDataInterface::getTask()
	 */
	public function getTask() : TaskInterface
	{
		return $this->task;
	}

    /**
     * @inheritDoc
     * @see ETLStepDataInterface::getNoteTaker()
     */
    public function getNoteTaker() : StepNoteTaker
    {
        return $this->noteTaker;
    }

    /**
     * @inheritDoc
     * @see ETLStepDataInterface::getProfiler()
     */
    public function getProfiler() : Profiler
    {
        return $this->profiler;
    }
}