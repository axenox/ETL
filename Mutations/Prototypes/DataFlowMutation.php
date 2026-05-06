<?php

namespace axenox\ETL\Mutations\Prototypes;

use axenox\ETL\Common\DataFlow;
use axenox\ETL\Interfaces\DataFlowInterface;
use exface\Core\CommonLogic\Mutations\AbstractMutation;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\Mutations\AppliedMutationInterface;
use exface\Core\Interfaces\Mutations\AppliedMutationOnArrayInterface;
use exface\Core\Mutations\AppliedMutationOnArray;

class DataFlowMutation extends AbstractMutation
{
    private ?string $changeName = null;
    private ?string $changeVersion = null;
    private ?string $changeDescription = null;
    private ?UxonObject $changeStepsUxon = null;

    /**
     * {@inheritdoc} 
     */
    public function apply($subject): AppliedMutationInterface
    {
        if (! $this->supports($subject)) {
            throw new InvalidArgumentException('Cannot apply page mutation to ' . get_class($subject) . ' - subject must be a DataFLow!');
        }

        /* @var $subject DataFlow */
        $stateBefore = [
            'name'        => $subject->getName(),
            'version'     => $subject->getVersion(),
            'description' => $subject->getDescription(),
        ];

        if ($this->changeName !== null) {
            $subject->setName($this->changeName);
        }
        if ($this->changeVersion !== null) {
            $subject->setVersion($this->changeVersion);
        }
        if ($this->changeDescription !== null) {
            $subject->setDescription($this->changeDescription);
        }
        if($this->changeStepsUxon !== null) {
            $workbench = $this->getWorkbench();
            foreach ($this->changeStepsUxon as $stepMutationUxon) {
                $mutation = new FlowStepMutation($workbench, $stepMutationUxon);
                $name = $mutation->getStepName();
                foreach ($subject->getStepGroup()->getSteps() as $step) {
                    if($step->getName() !== $name) {
                        continue;
                    }

                    $applied = $mutation->apply($step);

                    if ($applied instanceof AppliedMutationOnArrayInterface) {
                        $stateBefore['steps'][$name]    = $applied->dumpStateBeforeAsArray();
                        $stateAfter['steps'][$name]     = $applied->dumpStateAfterAsArray();
                    }
                    
                    break;
                }
            }
        }

        $stateAfter['name']         = $subject->getName();
        $stateAfter['version']      = $subject->getVersion();
        $stateAfter['description']  = $subject->getDescription();

        return new AppliedMutationOnArray($this, $subject, $stateBefore, $stateAfter);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($subject): bool
    {
        return $subject instanceof DataFlowInterface;
    }

    /**
     * Overwrites the name of the flow.
     *
     * @uxon-property change_name
     * @uxon-type string
     *
     * @param string $value
     * @return $this
     */
    protected function setChangeName(string $value): DataFlowMutation
    {
        $this->changeName = $value;
        return $this;
    }

    /**
     * Overwrites the version of the flow.
     *
     * @uxon-property change_version
     * @uxon-type string
     *
     * @param string $value
     * @return $this
     */
    protected function setChangeVersion(string $value): DataFlowMutation
    {
        $this->changeVersion = $value;
        return $this;
    }

    /**
     * Overwrites the description of the flow.
     *
     * @uxon-property change_description
     * @uxon-type string
     *
     * @param string $value
     * @return $this
     */
    protected function setChangeDescription(string $value): DataFlowMutation
    {
        $this->changeDescription = $value;
        return $this;
    }

    /**
     * Applies one or more step mutations to steps in this flow.
     *
     * @uxon-property change_steps
     * @uxon-type \axenox\ETL\Mutations\Prototypes\FlowStepMutation[]
     * @uxon-template [{"step":"", "change_uxon":""}]
     *
     * @param UxonObject $value
     * @return $this
     */
    protected function setChangeSteps(UxonObject $value): DataFlowMutation
    {
        $this->changeStepsUxon = $value;
        return $this;
    }

    /**
     * @return UxonObject|null
     */
    protected function getChangeStepsUxon(): ?UxonObject
    {
        return $this->changeStepsUxon;
    }
}