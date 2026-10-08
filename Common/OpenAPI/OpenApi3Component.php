<?php

namespace axenox\ETL\Common\OpenAPI;

use exface\Core\Exceptions\NotImplementedError;
use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * Holds a set of reusable objects for different aspects of the OAS. 
 * All objects defined within the components object will have no effect 
 * on the API unless they are explicitly referenced from properties outside the components object.
 * 
 * @link https://github.com/OAI/OpenAPI-Specification/blob/3.0.2/versions/3.0.2.md#componentsObject
 */
class OpenApi3Component implements iCanBeConvertedToUxon
{
    use OpenAPI3UxonTrait;
    
    /**
     * 
     * 
     * @uxon-property schemas
     * @uxon-type \axenox\ETL\Common\OpenAPI\OpenAPI3ObjectSchema[]
     * 
     * @return mixed
     */
    public function getSchemas()
    {
        throw new NotImplementedError('This method is a stub!');
    }
}