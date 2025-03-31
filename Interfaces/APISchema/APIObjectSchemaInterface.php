<?php
namespace axenox\ETL\Interfaces\APISchema;

use exface\Core\Interfaces\Model\MetaObjectInterface;

interface APIObjectSchemaInterface
{
    public function getAPI() : APISchemaInterface;

    public function getMetaObject() : ?MetaObjectInterface;

    /**
     * 
     * @return string[]
     */
    public function getPropertyNames() : array;

    /**
     * 
     * @return APIPropertyInterface[]
     */
    public function getProperties() : array;

    /**
     * 
     * @param string $format
     * @param string $option
     * @return null|string|number|bool|array
     */
    public function getFormatOption(string $format, string $option) : mixed;
}