<?php
namespace axenox\ETL\Interfaces\APISchema;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\ExpressionInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;

/**
 * Interface for API schema properties, that represent business object attributes or data columns
 * 
 * @author Andrej Kabachnik
 */
interface APIPropertyInterface
{
    /**
     * 
     * @return APIObjectSchemaInterface
     */
    public function getObjectSchema() : APIObjectSchemaInterface;

    /**
     * 
     * @return string
     */
    public function getPropertyName() : string;

    /**
     * Returns the type of this property according to the type system of the API schema
     * 
     * @return string
     */
    public function getPropertyType() : string;

    /**
     * 
     * @return bool
     */
    public function isBoundToAttribute() : bool;

    /**
     * 
     * @return string|null
     */
    public function getAttributeAlias() : ?string;

    /**
     * 
     * @return MetaAttributeInterface|null
     */
    public function getAttribute() : ?MetaAttributeInterface;

    /**
     * 
     * @return bool
     */
    public function hasLookup() : bool;

    /**
     * 
     * @return UxonObject|null
     */
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

    /**
     * 
     * @return bool
     */
    public function isBoundToMetamodel() : bool;

    /**
     * 
     * @return bool
     */
    public function isBoundToCalculation() : bool;

    /**
     * 
     * @return ExpressionInterface|null
     */
    public function getCalculationExpression() : ?ExpressionInterface;

    /**
     * Returns the best-matching meta model data type for this property
     * 
     * @return DataTypeInterface
     */
    public function guessDataType() : DataTypeInterface;

    /**
     * 
     * @return bool
     */
    public function isNullable() : bool;

    /**
     * 
     * @return bool
     */
    public function isRequired() : bool;
}