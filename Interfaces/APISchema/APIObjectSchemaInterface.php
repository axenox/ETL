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

    public function getJsonSchema() : array;

    public function getUpdateIfMatchingAttributeAliases() : array;
    
    /**
     * The attributes to compare when searching for existing data rows.
     * 
     * If an existing item of the to-object with exact the same values in all of these attributes
     * is found, the step will perform an update and will not create a new item.
     * 
     * **NOTE:** this will overwrite data in all the attributes affected by the `mapper`.
     * 
     * @return bool
     */
    public function isUpdateIfMatchingAttributes() : bool;
}