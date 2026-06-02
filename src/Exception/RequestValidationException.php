<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Exception;

/**
 * Exception thrown when request validation fails
 */
class RequestValidationException extends \Exception
{
    /** @var list<string> */
    private array $validationErrors;
    private string $schemaName;
    /** @var array<array-key, mixed> */
    private array $requestData;

    /**
     * Constructor
     *
     * @param list<string> $validationErrors The validation errors
     * @param string $schemaName The name of the schema that failed validation
     * @param array<array-key, mixed> $requestData The request data that failed validation
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous exception
     */
    public function __construct(
        array $validationErrors, 
        string $schemaName,
        array $requestData = [],
        string $message = 'Validation failed', 
        int $code = 400, 
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->validationErrors = $validationErrors;
        $this->schemaName = $schemaName;
        $this->requestData = $requestData;
    }

    /**
     * Get the validation errors
     *
     * @return list<string> The validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
    
    /**
     * Get the schema name
     *
     * @return string The schema name
     */
    public function getSchemaName(): string
    {
        return $this->schemaName;
    }
    
    /**
     * Get the request data
     *
     * @return array<array-key, mixed> The request data
     */
    public function getRequestData(): array
    {
        return $this->requestData;
    }
}
