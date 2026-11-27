<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\RateLimit;

use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A plugin's rate limit is spent (contract C15).
 *
 * The API envelope answers it with `429`, the error `rate_limited` and the
 * seconds to wait in `meta.retry_after` (also the `Retry-After` header): the
 * mapping is {@see \App\Service\Core\ApiResponseFormatter::formatRateLimited()}.
 * It extends {@see ServiceException} so that a controller that catches service
 * exceptions and one that lets it bubble to the exception listener answer the
 * same way.
 */
final class PluginRateLimitedException extends ServiceException
{
    /** The error text (and so the machine code) of the 429 answer. */
    public const ERROR = 'rate_limited';

    public function __construct(
        private readonly string $pluginId,
        private readonly string $limitName,
        private readonly int $retryAfterSeconds,
    ) {
        parent::__construct(self::ERROR, Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function getPluginId(): string
    {
        return $this->pluginId;
    }

    /** The name of the limit in the plugin's manifest `rateLimits`. */
    public function getLimitName(): string
    {
        return $this->limitName;
    }

    /** Whole seconds until the caller may try again; at least 1. */
    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
