<?php
namespace axenox\ETL\Interfaces;

use exface\Core\Interfaces\Tasks\TaskInterface;

interface ETLStepDataInterface
{
	public function getFlowRunUid() : string;
	
	public function getStepRunUid() : string;
	
	public function setStepRunUid(string $value) : ETLStepDataInterface;
	
	public function getPreviousResult( ): ?ETLStepResultInterface;
	
	public function getLastResult() : ?ETLStepResultInterface;
	
	public function getTask() : TaskInterface;
}