<?php
namespace axenox\ETL\Interfaces;

use axenox\ETL\ETLPrototypes\StepGroup;
use exface\Core\Interfaces\WorkbenchDependantInterface;

interface DataFlowInterface extends WorkbenchDependantInterface
{
    /**
     * Returns the alias of the flow.
     *
     * @return string
     */
    public function getAlias() : string;

    /**
     * Returns the unique identifier of the flow.
     *
     * @return string
     */
    public function getUid() : string;

    /**
     * Returns a human-readable execution plan for this flow.
     *
     * @return string
     */
    public function printExecutionPlan() : string;

    /**
     * Returns the display name of the flow.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Sets the display name of the flow.
     *
     * @param string $name
     * @return DataFlowInterface
     */
    public function setName(string $name): DataFlowInterface;

    /**
     * Returns the version string of the flow, or null if no version is set.
     *
     * @return string|null
     */
    public function getVersion(): ?string;

    /**
     * Sets the version string of the flow.
     *
     * @param string $version
     * @return DataFlowInterface
     */
    public function setVersion(string $version): DataFlowInterface;

    /**
     * Returns the description of the flow, or null if no description is set.
     *
     * @return string|null
     */
    public function getDescription(): ?string;

    /**
     * Sets the description of the flow.
     *
     * @param string $description
     * @return DataFlowInterface
     */
    public function setDescription(string $description): DataFlowInterface;

    /**
     * Runs the flow and yields log messages or step results.
     *
     * @param ETLStepDataInterface $stepData
     * @return \Generator
     */
    public function run(ETLStepDataInterface $stepData): \Generator;

    /**
     * Returns the maximum number of seconds the flow is allowed to run.
     *
     * @return int
     */
    public function getTimeout(): int;

    /**
     * Returns the root step group.
     *
     * @return StepGroup
     */
    public function getStepGroup() : StepGroup;
}