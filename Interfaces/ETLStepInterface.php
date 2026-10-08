<?php
namespace axenox\ETL\Interfaces;

use exface\Core\Interfaces\Model\MetaObjectInterface;

interface ETLStepInterface extends DataFlowStepInterface
{
    /**
     * Returns the source meta object this step reads data from.
     *
     * @return MetaObjectInterface
     */
    public function getFromObject() : MetaObjectInterface;

    /**
     * Sets the source meta object this step reads data from.
     *
     * @param MetaObjectInterface $object
     * @return ETLStepInterface
     */
    public function setFromObject(MetaObjectInterface $object) : ETLStepInterface;

    /**
     * Returns the target meta object this step writes data to.
     *
     * @return MetaObjectInterface
     */
    public function getToObject() : MetaObjectInterface;

    /**
     * Sets the target meta object this step writes data to.
     *
     * @param MetaObjectInterface $object
     * @return ETLStepInterface
     */
    public function setToObject(MetaObjectInterface $object) : ETLStepInterface;

}