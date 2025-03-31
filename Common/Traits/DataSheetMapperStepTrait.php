<?php
namespace axenox\ETL\Common\Traits;

use axenox\ETL\Interfaces\DataFlowStepInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataSheets\DataSheetMapperInterface;
use exface\Core\Factories\DataSheetMapperFactory;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\UxonParserError;
use exface\Core\DataTypes\PhpClassDataType;

/**
 * 
 * 
 * @author andrej.kabachnik
 *
 */
trait DataSheetMapperStepTrait
{    
    private $mapperUxon = null;
    
    /**
     * 
     * @param MetaObjectInterface $fromObject
     * @param MetaObjectInterface $toObject
     * @return DataSheetMapperInterface
     */
    protected function getMapper(array $placeholders = []) : DataSheetMapperInterface
    {
        if (! $this->mapperUxon || $this->mapperUxon->isEmpty()) {
            throw new UxonParserError($this->exportUxonObject(), 'Missing `mapper` in property in configuration of ETL prototype ' . PhpClassDataType::findClassNameWithoutNamespace(get_class($this)));
        }
        
       $json = $this->mapperUxon->toJson();
       $json = StringDataType::replacePlaceholders($json, $placeholders);
       
       return DataSheetMapperFactory::createFromUxon($this->getWorkbench(), UxonObject::fromJson($json), $this->getFromObject(), $this->getToObject()); 
    }
    
    /**
     * Data sheet mapper to be applied to the `from_data_sheet` in order to get the data for the to-object.
     * 
     * The syntax and functionality is the same as that of `input_mapper` in actions.
     * 
     * Example:
     * 
     * ```
     *  {
     *      "column_to_column_mappings": [
     *          {
     *              "from": "attribute_of_your_from_object", 
     *              "to": "attribute_of_your_to_object"
     *          }
     *      ]
     *  }
     * 
     * ```
     * 
     * @uxon-property mapper
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheetMapper
     * @uxon-template {"column_to_column_mappings": [{"from": "", "to": ""}]}
     * @uxon-required true
     * 
     * @param UxonObject $uxon
     * @return \axenox\ETL\Interfaces\DataFlowStepInterface
     */
    protected function setMapper(UxonObject $uxon) : DataFlowStepInterface
    {
        $this->mapperUxon = $uxon;
        return $this;
    }
}