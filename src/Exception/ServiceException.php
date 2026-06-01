<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Exception thrown by services with additional data and status code
 */
class ServiceException extends \Exception
{
    /** @var array<string, mixed>|null */
    private ?array $data;
    
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(string $message, int $code = Response::HTTP_BAD_REQUEST, ?array $data = null) 
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
    
    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array 
    {
        return $this->data;
    }
}
