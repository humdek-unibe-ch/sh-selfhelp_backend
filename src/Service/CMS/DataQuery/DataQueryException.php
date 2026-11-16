<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A data query that is not valid: an unknown column, an operator that does not
 * fit the column type, a value that cannot be read as the column type, or a
 * bound that is exceeded. Always a 400 for the caller; nothing was executed.
 */
final class DataQueryException extends ServiceException
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(string $message, ?array $data = null)
    {
        parent::__construct($message, Response::HTTP_BAD_REQUEST, $data);
    }
}
