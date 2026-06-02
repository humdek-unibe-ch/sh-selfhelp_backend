<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Trait;

use App\Exception\RequestValidationException;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Trait for validating API requests against JSON schemas
 */
trait RequestValidatorTrait
{
    /**
     * Validates a request against a JSON schema
     *
     * @param Request $request The request to validate
     * @param string $schemaName The name of the schema to validate against (e.g., 'requests/auth/login')
     * @param JsonSchemaValidationService $jsonSchemaValidationService The JSON schema validation service
     * @return array<array-key, mixed> The validated request data (object payloads are string-keyed, batch payloads are lists)
     * @throws RequestValidationException If validation fails
     * @throws \InvalidArgumentException If the request body is not valid JSON
     */
    protected function validateRequest(
        Request $request,
        string $schemaName,
        JsonSchemaValidationService $jsonSchemaValidationService
    ): array {
        // Parse JSON request body
        $decoded = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON payload: ' . json_last_error_msg());
        }

        // API request bodies must be JSON objects/arrays; anything scalar fails validation below.
        $data = is_array($decoded) ? $decoded : [];

        // Validate against schema
        $validationErrors = $jsonSchemaValidationService->validate($this->convertToObject($data), $schemaName);
        if (!empty($validationErrors)) {
            throw new RequestValidationException(
                $validationErrors,
                $schemaName,
                $data,
                'Request validation failed for schema: ' . $schemaName . ' with errors: ' . json_encode($validationErrors)
            );
        }

        return $data;
    }

    /**
     * Normalise a single mixed value into a string-keyed associative array.
     *
     * @return array<string, mixed>
     */
    protected function toAssocArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * Normalise a mixed value into a list of integers (non-numeric entries dropped).
     *
     * @return list<int>
     */
    protected function toIntList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $result[] = (int) $item;
            }
        }

        return $result;
    }

    /**
     * Normalise a mixed value into a list of strings (non-scalar entries dropped).
     *
     * @return list<string>
     */
    protected function toStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $result[] = (string) $item;
            }
        }

        return $result;
    }

    /**
     * Normalise a mixed value into a list of string-keyed associative arrays.
     *
     * @return list<array<string, mixed>>
     */
    protected function asListOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $result[] = $this->toAssocArray($entry);
            }
        }

        return $result;
    }

    /**
     * Read an integer field from validated request data, coercing scalars safely.
     *
     * @param array<array-key, mixed> $data
     */
    protected function asIntField(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read an optional integer field from validated request data.
     *
     * @param array<array-key, mixed> $data
     */
    protected function asIntOrNullField(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Read a string field from validated request data, coercing scalars safely.
     *
     * @param array<array-key, mixed> $data
     */
    protected function asStringField(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Read an optional string field from validated request data.
     *
     * @param array<array-key, mixed> $data
     */
    protected function asStringOrNullField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Read a boolean field from validated request data.
     *
     * @param array<array-key, mixed> $data
     */
    protected function asBoolField(array $data, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        return filter_var($data[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Read an array field from validated request data, guaranteeing string keys.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    protected function asArrayField(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * Read an optional array field from validated request data.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>|null
     */
    protected function asNullableArrayField(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * @return ($value is array ? object|array<array-key, mixed> : mixed)
     */
    private function convertToObject(mixed $value): mixed
    {
        if (is_array($value)) {
            // Fix: empty array is not associative
            if ($value === []) {
                return [];
            }

            // Check if associative
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                return (object) array_map([$this, 'convertToObject'], $value);
            } else {
                return array_map([$this, 'convertToObject'], $value);
            }
        }
        return $value;
    }
}
