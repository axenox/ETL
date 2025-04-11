<?php
namespace axenox\ETL\Interfaces;

use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use exface\Core\Interfaces\Facades\FacadeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Common interface for facades, that work with API schema models
 * 
 * @author Andrej Kabachnik
 */
interface ApiSchemaFacadeInterface extends FacadeInterface
{
    public function getApiSchemaForRequest(ServerRequestInterface $request) : ?APISchemaInterface;
}