<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The visitor's list state in the URL cannot be honoured. Always a 400 (a
 * malformed or unknown parameter) or a 403 (a mode the list does not offer);
 * the `parameter` in the error data names the culprit so clients can drop it.
 */
final class FormListStateException extends ServiceException
{
    public function __construct(string $message, string $reason, ?string $parameter = null, int $code = Response::HTTP_BAD_REQUEST)
    {
        parent::__construct($message, $code, array_filter(
            ['reason' => $reason, 'parameter' => $parameter],
            static fn (?string $value): bool => $value !== null,
        ));
    }
}
