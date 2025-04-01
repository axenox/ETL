<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Interfaces\APISchema\APIObjectSchemaInterface;
use axenox\ETL\Interfaces\APISchema\APIPropertyInterface;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\BinaryDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\StringEnumDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\ExpressionFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\ExpressionInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;

class OpenAPI3Property implements APIPropertyInterface
{
    const X_ATTRIBUTE_ALIAS = 'x-attribute-alias';
    const X_LOOKUP = 'x-lookup';
    const X_CALCULATION = 'x-calculation';
    const X_DATA_ADDRESS = 'x-data-address';

    private $objectSchema = null;
    private $jsonSchema = null;
    private $attribute = null;
    private $workbench = null;

    public function __construct(OpenAPI3ObjectSchema $objectSchema, array $jsonSchema)
    {
        $this->objectSchema = $objectSchema;
        $this->jsonSchema = $jsonSchema;
        $this->workbench = $objectSchema->getAPI()->getWorkbench();
    }

    public function getObjectSchema(): APIObjectSchemaInterface
    {
        return $this->objectSchema;
    }

    public function hasLookup() : bool
    {
        return null !== $this->jsonSchema[self::X_LOOKUP] ?? null;
    }

    public function getLookupUxon() : ?UxonObject
    {
        if (! $this->hasLookup()) {
            return null;
        }
        return new UxonObject($this->jsonSchema[self::X_LOOKUP]);
    }

    public function isBoundToAttribute() : bool
    {
        return null !== $this->getAttributeAlias();
    }

    public function getAttributeAlias() : ?string
    {
        return $this->jsonSchema[self::X_ATTRIBUTE_ALIAS] ?? null;
    }

    public function getAttribute() : ?MetaAttributeInterface
    {
        if ($this->attribute === null) {
            if (null !== $alias = $this->getAttributeAlias()) {
                if (null !== $customAddress = $this->jsonSchema[self::X_DATA_ADDRESS]) {
                    $this->attribute = MetaObjectFactory::addAttributeTemporary($this->getObjectSchema()->getMetaObject(), $alias, $alias, $customAddress, $this->guessDataType());
                } else {
                    $this->attribute = $this->getObjectSchema()->getMetaObject()->getAttribute($alias);
                }
            }
        }
        return $this->attribute;
    }

    public function isBoundToFormat(string $format) : bool
    {
        foreach (array_keys($this->jsonSchema) as $prop) {
            if (StringDataType::startsWith($prop, 'x-' . $format . '-')) {
                return true;
            }
        }
        return false;
    }

    public function getFormatOption(string $format, string $option) : mixed 
    {
        $value = $this->jsonSchema['x-' . $format . '-' . $option] ?? null;
        $value = $this->replacePlaceholders($value);
        $value = $this->evaluateFormulas($value);
        return $value;
    }

    public function getPropertyType() : string
    {
        return $this->jsonSchema['type'];
    }

    public function guessDataType() : DataTypeInterface
    {
        return $this->findDataType($this->jsonSchema['type'], $this->jsonSchema['format'], $this->jsonSchema['enum']);
    }

    public function isBoundToMetamodel() : bool
    {
        return $this->isBoundToAttribute();
    }

    public function isBoundToCalculation() : bool
    {
        return null !== $this->jsonSchema[self::X_CALCULATION];
    }

    public function getCalculationExpression() : ?ExpressionInterface
    {
        if (! $this->isBoundToCalculation()) {
            return null;
        }
        return ExpressionFactory::createFromString($this->workbench, $this->jsonSchema[self::X_CALCULATION], $this->getObjectSchema()->getMetaObject());
    }
    protected function findDataType($openApiType, $format, $enumValues) : DataTypeInterface
    {
        $workbench = $this->workbench;
        switch ($openApiType) {
            case 'integer':
                return DataTypeFactory::createFromString($workbench, IntegerDataType::class);

            case 'number':
                return DataTypeFactory::createFromString($workbench, NumberDataType::class);

            case 'boolean':
                return DataTypeFactory::createFromString($workbench, BooleanDataType::class);

            case 'array':
                return DataTypeFactory::createFromString($workbench, ArrayDataType::class);

            case 'string':
                if ($format === 'datetime' || $format === 'date') {
                    return DataTypeFactory::createFromString($workbench, $format === 'datetime' ? DateTimeDataType::class : DateTimeDataType::class);
                }
                if ($format === 'byte' || $format === 'binary') {
                    $binaryType = DataTypeFactory::createFromString($workbench, BinaryDataType::class);
                    if ($binaryType instanceof  BinaryDataType) {
                        $binaryType->setEncoding($format === 'byte' ? 'base64' : 'binary');
                    }
                    return $binaryType;
                }
                if ($format === 'datetime') {
                    return DataTypeFactory::createFromString($workbench, DateTimeDataType::class);
                }
                if ($format === 'date') {
                    return DataTypeFactory::createFromString($workbench, DateDataType::class);
                }
                if ($enumValues !== null) {
                    $enumType = DataTypeFactory::createFromString($workbench, StringEnumDataType::class);
                    // In PowerUi we map keys to the values. In this case, the value also needs to be the key, since the OpenApi definition only has one value for the enum.
                    $enumValues = array_combine($enumValues, $enumValues);
                    if ($enumType instanceof EnumDataTypeInterface) {
                        $enumType->setValues($enumValues);
                    }
                    return $enumType;
                }

                return DataTypeFactory::createFromString($workbench, StringDataType::class);

            default:
                throw new InvalidArgumentException('Openapi schema type: ' . $openApiType . ' not recognized.');
        }
    }

    protected function replacePlaceholders(string $value) : string
    {
        return StringDataType::replacePlaceholders($value, $this->jsonSchema);
    }

    protected function evaluateFormulas(string $value) : string
    {
        if (Expression::detectFormula($value) === true) {
            $expr = ExpressionFactory::createFromString($this->workbench, $value);
            if ($expr->isStatic()) {
                return $expr->evaluate() ?? '';
            } else {
                throw new RuntimeException('Cannot use dynamic formula "' . $value . '" in an OpenAPI custom attribute!');
            }
        }
        return $value;
    }
}