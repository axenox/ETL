<?php
namespace axenox\ETL\Interfaces\APISchema;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;

interface APIPropertyInterface
{
    public function getObjectSchema() : APIObjectSchemaInterface;

    public function isBoundToAttribute() : bool;

    /**
     * 
     * @return string|null
     */
    public function getAttributeAlias() : ?string;

    public function getAttribute() : ?MetaAttributeInterface;

    public function hasLookup() : bool;

    public function getLookupUxon() : ?UxonObject;

    /**
     * 
     * @param string $format
     * @return bool
     */
    public function isBoundToFormat(string $format) : bool;

    /**
     * 
     * @param string $format
     * @param string $option
     * @return null|string|number|bool|array
     */
    public function getFormatOption(string $format, string $option) : mixed;

    public function getPropertyType() : string;

    public function getDataType() : DataTypeInterface;
}