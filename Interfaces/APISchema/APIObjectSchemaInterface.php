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
     * @param string $name
     * @return APIPropertyInterface|null
     */
    public function getProperty(string $name) : ?APIPropertyInterface;

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

    /**
     * @return bool
     */
    public function hasUidProperties() : bool;

    /**
     * @return string|null
     */
    public function getUidProperties() : null|array;

    /**
     * @return bool
     */
    public function hasLabelProperty() : bool;

    /**
     * @return string|null
     */
    public function getLabelPropertyName() : ?string;

    /**
     * 
     * @param array $arrayOfRows
     * @return array
     */
    public function validateRows(array $arrayOfRows) : array;

    
    public function validateRow(array $properties) : array;
}