<?php
namespace axenox\ETL\Common\OpenAPI;

use axenox\ETL\Uxon\OpenAPISchema;
use exface\Core\CommonLogic\UxonObject;

/**
 * Contains methods to make OpenAPI schemas compatible with UXON
 * 
 * @author Andrej Kabachnik
 */
trait OpenAPI3UxonTrait
{
    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::importUxonObject()
     */
    public function importUxonObject(UxonObject $uxon, array $skip_property_names = array())
    {
        
    }

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return new UxonObject();
    }
    
    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::getUxonSchemaClass()
     */
    public static function getUxonSchemaClass() : ?string
    {
        return OpenAPISchema::class;
    }
}