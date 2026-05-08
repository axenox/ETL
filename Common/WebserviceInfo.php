<?php

namespace axenox\ETL\Common;

use axenox\ETL\Facades\DataFlowFacade;
use axenox\ETL\Interfaces\APISchema\APISchemaInterface;

class WebserviceInfo
{
    private ?string $name;
    private ?string $version;
    private ?string $uid;
    private APISchemaInterface $webservice;
    private DataFlowFacade $facade;

    public function __construct(
        ?string            $name,
        ?string            $version,
        ?string            $uid,
        APISchemaInterface $webservice,
        DataFlowFacade     $facade
    )
    {
        $this->name = $name;
        $this->version = $version;
        $this->uid = $uid;
        $this->webservice = $webservice;
        $this->facade = $facade;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return string|null
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }

    /**
     * @return string|null
     */
    public function getUid(): ?string
    {
        return $this->uid;
    }

    /**
     * @return APISchemaInterface
     */
    public function getWebservice(): APISchemaInterface
    {
        return $this->webservice;
    }

    /**
     * @return DataFlowFacade
     */
    public function getFacade(): DataFlowFacade
    {
        return $this->facade;
    }
}