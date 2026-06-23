<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\Core;

use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base service with error handling capabilities
 * 
 * Add the trait individually to services that need caching to avoid circular dependencies
 */
abstract class BaseService
{
    /**
     * Throw a not found exception
     */
    protected function throwNotFound(string $message = 'Resource not found'): never
    {
        throw new ServiceException($message, Response::HTTP_NOT_FOUND);
    }
    
    /**
     * Throw an unauthorized (401) exception
     */
    protected function throwUnauthorized(string $message = 'Authentication required'): never
    {
        throw new ServiceException($message, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Throw a forbidden exception
     */
    protected function throwForbidden(string $message = 'Access denied'): never
    {
        throw new ServiceException($message, Response::HTTP_FORBIDDEN);
    }
    
    /**
     * Throw a bad request exception
     */
    protected function throwBadRequest(string $message = 'Bad request'): never
    {
        throw new ServiceException($message, Response::HTTP_BAD_REQUEST);
    }
    
    /**
     * Throw a validation exception with validation errors
     *
     * @param array<string, mixed> $errors
     */
    protected function throwValidationError(string $message = 'Validation failed', array $errors = []): never
    {
        throw new ServiceException($message, Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }
    
    /**
     * Throw a conflict exception
     */
    protected function throwConflict(string $message = 'Resource already exists'): never
    {
        throw new ServiceException($message, Response::HTTP_CONFLICT);
    }

    /**
     * Coerce a mixed value (typically decoded JSON / request input) to a string.
     *
     * Mirrors the runtime semantics of a plain `(string)` cast for scalar and
     * Stringable values (the only values these call sites ever receive). For a
     * non-stringable array/object — which a `(string)` cast would already turn
     * into a runtime warning — it returns an empty string instead of emitting a
     * warning, so valid inputs behave identically and invalid inputs degrade
     * gracefully rather than fatally.
     */
    protected function asString(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /**
     * Coerce a mixed value to a string, preserving nulls.
     */
    protected function asStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }

    /**
     * Coerce a mixed value (typically decoded JSON / request input) to an int.
     *
     * Mirrors the runtime semantics of a plain `(int)` cast for scalar values;
     * non-scalar values (which `(int)` would map to 0/1) resolve to 0.
     */
    protected function asInt(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    /**
     * Coerce a mixed value to an int, preserving nulls.
     */
    protected function asIntOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (int) $value : null;
    }

    /**
     * Coerce a mixed value to an array, returning an empty array for non-arrays.
     *
     * @return array<mixed>
     */
    protected function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Coerce a mixed value (typically a decoded JSON object) to a
     * string-keyed array. Non-arrays become an empty array; integer keys
     * are stringified so the result satisfies array<string, mixed>. Runtime
     * values are unchanged for the JSON-object inputs these call sites receive.
     *
     * @return array<string, mixed>
     */
    protected function asAssocArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

}
