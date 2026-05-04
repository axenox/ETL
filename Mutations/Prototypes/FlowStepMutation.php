<?php

namespace axenox\ETL\Mutations\Prototypes;

use axenox\ETL\Interfaces\DataFlowStepInterface;
use axenox\ETL\Interfaces\ETLStepInterface;
use exface\Core\CommonLogic\Mutations\AbstractMutation;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Mutations\AppliedMutationInterface;
use exface\Core\Mutations\AppliedMutationOnArray;

class FlowStepMutation extends AbstractMutation
{
    private ?string $changeName = null;
    private ?string $changeFromObject = null;
    private ?string $changeToObject = null;
    private ?string $stepName = null;
    private ?UxonObject $changeUxon = null;

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Mutations\MutationInterface::apply()
     */
    public function apply($subject): AppliedMutationInterface
    {
        if (! $this->supports($subject)) {
            throw new InvalidArgumentException('Cannot apply flow step mutation to ' . get_class($subject) . ' - subject must be an ETLStepInterface!');
        }

        /* @var $subject ETLStepInterface */
        $stateBefore = [
            'name'        => $subject->getName(),
            'from_object' => $subject->getFromObject()->getAliasWithNamespace(),
            'to_object'   => $subject->getToObject()->getAliasWithNamespace(),
            'uxon'        => $subject->exportUxonObject()->toArray(),
        ];

        if ($this->changeName !== null) {
            $subject->setName($this->changeName);
        }
        if ($this->changeFromObject !== null) {
            $subject->setFromObject(MetaObjectFactory::createFromString($this->getWorkbench(), $this->changeFromObject));
        }
        if ($this->changeToObject !== null) {
            $subject->setToObject(MetaObjectFactory::createFromString($this->getWorkbench(), $this->changeToObject));
        }
        if ($this->changeUxon !== null) {
            $subject->importUxonObject($this->changeUxon);
        }

        $stateAfter = [
            'name'        => $subject->getName(),
            'from_object' => $subject->getFromObject()->getAliasWithNamespace(),
            'to_object'   => $subject->getToObject()->getAliasWithNamespace(),
            'uxon'        => $subject->exportUxonObject()->toArray(),
        ];

        return new AppliedMutationOnArray($this, $subject, $stateBefore, $stateAfter);
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Mutations\MutationInterface::supports()
     */
    public function supports($subject): bool
    {
        return 
            $this->getStepName() !== null &&
            $subject instanceof DataFlowStepInterface &&
            $subject->getName() === $this->getStepName();
    }

    /**
     * Overwrites the name of the step.
     *
     * @uxon-property change_name
     * @uxon-type string
     *
     * @param string $value
     * @return $this
     */
    protected function setChangeName(string $value): FlowStepMutation
    {
        $this->changeName = $value;
        return $this;
    }

    /**
     * Overwrites the source (from) meta object alias of the step.
     *
     * @uxon-property change_from_object
     * @uxon-type metamodel:object
     *
     * @param string $value
     * @return $this
     */
    protected function setChangeFromObject(string $value): FlowStepMutation
    {
        $this->changeFromObject = $value;
        return $this;
    }

    /**
     * Overwrites the target (to) meta object alias of the step.
     *
     * @uxon-property change_to_object
     * @uxon-type metamodel:object
     *
     * @param string $value
     * @return $this
     */
    protected function setChangeToObject(string $value): FlowStepMutation
    {
        $this->changeToObject = $value;
        return $this;
    }

    /**
     * Modifies the UXON configuration of the step by applying UXON mutation rules.
     *
     * @uxon-property change_uxon
     * @uxon-type \exface\Core\Mutations\Prototypes\GenericUxonMutation
     * @uxon-template {"": ""}
     *
     * @param UxonObject $uxon
     * @return $this
     */
    protected function setChangeUxon(UxonObject $uxon): FlowStepMutation
    {
        $this->changeUxon = $uxon;
        return $this;
    }

    /**
     * The `name` of the step you wish to modify.
     *
     * @uxon-property step
     * @uxon-type metamodel:axenox.ETL.step:name
     *
     * @param string $value
     * @return $this
     */
    protected function setStep(string $value): FlowStepMutation
    {
        $this->stepName = $value;
        return $this;
    }

    /**
     * Returns the `name` of the step this mutation applies to.
     *
     * @return string|null
     */
    public function getStepName(): ?string
    {
        return $this->stepName;
    }
}










