<?php

namespace axenox\ETL\Common;

use axenox\ETL\Facades\DataFlowFacade;

/**
 * This class represents a web service and its constituent properties and should not contain any logic.
 *
 * Bundles together the identifying information of a web service
 * (name, version and UID), its API schema definition and the
 * {@link DataFlowFacade} that serves it. It also tracks whether the
 * web service is currently enabled.
 *
 * The object is mostly immutable - only the schema array can be replaced
 * after construction via {@link self::setSchemaArray()}.
 *
 * @author Georg Bieger
 */
class WebserviceInfo
{
    private ?string $name;
    private ?string $version;
    private ?string $uid;
    private array $schemaArray;
    private ?DataFlowFacade $facade;
    private bool $enabled;

    /**
     * @param string|null         $name        The unique name of the web service
     * @param string|null         $version     The version identifier of the web service
     * @param string|null         $uid         The UID of the web service
     * @param array               $schemaArray The API schema definition as an array
     * @param DataFlowFacade|null $facade      The facade serving this web service
     * @param bool                $enabled     Whether the web service is enabled (default: true)
     */
    public function __construct(
        ?string         $name,
        ?string         $version,
        ?string         $uid,
        array           $schemaArray,
        ?DataFlowFacade $facade,
        bool            $enabled = true
    )
    {
        $this->name = $name;
        $this->version = $version;
        $this->uid = $uid;
        $this->schemaArray = $schemaArray;
        $this->facade = $facade;
        $this->enabled = $enabled;
    }

    /**
     * Returns the unique name of the web service.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Returns the version identifier of the web service.
     *
     * @return string|null
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }

    /**
     * Returns the UID of the web service.
     *
     * @return string|null
     */
    public function getUid(): ?string
    {
        return $this->uid;
    }

    /**
     * Returns the API schema definition of the web service.
     *
     * @return array
     */
    public function getSchemaArray(): array
    {
        return $this->schemaArray;
    }

    /**
     * Replaces the API schema definition of the web service.
     *
     * @param array $value The new schema definition
     * @return $this
     */
    public function setSchemaArray(array $value) : WebserviceInfo
    {
        $this->schemaArray = $value;
        return $this;
    }

    /**
     * Returns the facade that serves this web service.
     *
     * @return DataFlowFacade|null
     */
    public function getFacade(): ?DataFlowFacade
    {
        return $this->facade;
    }

    /**
     * Tells whether the web service is currently enabled.
     *
     * @return bool
     */
    public function isEnabled() : bool
    {
        return $this->enabled;
    }
}