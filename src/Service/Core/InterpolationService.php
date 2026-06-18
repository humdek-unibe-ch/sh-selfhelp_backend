<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\Core;

use App\Service\Core\BaseService;
use Psr\Log\LoggerInterface;

/**
 * Service for handling variable interpolation in content fields using Mustache templating
 *
 * Uses Mustache templating engine to replace {{variable_name}} patterns with actual values.
 * Supports nested data structures, custom helpers, and multiple data sources.
 * Provides a clean, professional API that's easily extensible and reusable.
 */
class InterpolationService extends BaseService
{
    private \Mustache\Engine $mustache;

    private ?LoggerInterface $logger;

    /**
     * Constructor - Initialize Mustache engine
     *
     * @param array<string, mixed> $config Configuration options:
     *   - custom_helpers: Array of custom Mustache helpers (optional)
     * @param LoggerInterface|null $logger Optional PSR logger. Null when the
     *   service is instantiated directly (e.g. in unit tests), in which case
     *   interpolation errors fall back to error_log.
     */
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger;

        // Create Mustache engine with explicit empty array
        $this->mustache = new \Mustache\Engine([]);

        // Add custom helpers if provided
        if (!empty($config['custom_helpers']) && is_array($config['custom_helpers'])) {
            foreach ($config['custom_helpers'] as $name => $helper) {
                $this->mustache->addHelper((string) $name, $helper);
            }
        }
    }

    /**
     * Perform Mustache rendering with error handling
     *
     * @param string $content The content containing {{variable}} patterns
     * @param array<array-key, array<array-key, mixed>> $dataArrays One or more arrays containing variable => value mappings
     * @return string The content with variables replaced or original content on error
     */
    private function performInterpolation(string $content, array $dataArrays): string
    {
        try {
            // Merge all data arrays into one context, with later arrays taking precedence
            $context = $this->mergeDataContexts($dataArrays);

            // Render the template with Mustache
            return $this->mustache->render($content, $context);

        } catch (\Exception $e) {
            // Log the error but return original content to maintain backward compatibility
            $this->logError('Mustache interpolation failed: ' . $e->getMessage());
            return $content;
        }
    }

    /**
     * Replace {{variable_name}} patterns in content with values from provided data
     *
     * Uses Mustache templating engine for robust variable interpolation with support for:
     * - Nested object properties: {{user.name}}
     * - Array indexing: {{items.0.name}}
     * - Conditional sections: {{#user}}...{{/user}}
     * - Loops: {{#items}}...{{/items}}
     * - Custom helpers and lambdas
     *
     * @param string $content The content containing {{variable}} patterns
     * @param array<array-key, mixed> $dataArrays One or more arrays containing variable => value mappings
     * @return string The content with variables replaced
     */
    public function interpolate(string $content, array ...$dataArrays): string
    {
        if (empty($content)) {
            return $content;
        }

        return $this->performInterpolation($content, $dataArrays);
    }

    /**
     * Interpolate variables in an array of content fields recursively
     *
     * Processes arrays recursively, interpolating string values while preserving
     * the structure of nested arrays and objects.
     *
     * @param array<array-key, mixed> $contentArray Array containing content fields to interpolate
     * @param array<array-key, mixed> $dataArrays One or more arrays containing variable => value mappings
     * @return array<array-key, mixed> The content array with variables replaced
     */
    public function interpolateArray(array $contentArray, array ...$dataArrays): array
    {
        $result = [];

        foreach ($contentArray as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->interpolate($value, ...$dataArrays);
            } elseif (is_array($value)) {
                $result[$key] = $this->interpolateArray($value, ...$dataArrays);
            } elseif (is_object($value)) {
                // For objects, convert to array, interpolate, then convert back
                $encoded = json_encode($value);
                $arrayValue = $encoded !== false ? json_decode($encoded, true) : null;
                if (is_array($arrayValue)) {
                    $interpolatedArray = $this->interpolateArray($arrayValue, ...$dataArrays);
                    $reEncoded = json_encode($interpolatedArray);
                    $result[$key] = $reEncoded !== false ? json_decode($reEncoded) : $value;
                } else {
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Interpolate condition with debug support
     *
     * Preserves the original condition as 'condition_original' before interpolating.
     * Handles JSON decoding for proper storage of the original condition.
     *
     * @param array<string, mixed> &$section The section array (passed by reference to modify condition_original)
     * @param-out array<string, mixed> $section
     * @param array<array-key, mixed> $dataArrays One or more arrays containing variable => value mappings
     * @return string The interpolated condition
     */
    public function interpolateConditionWithDebug(array &$section, array ...$dataArrays): string
    {
        // Get the original condition
        $originalCondition = $section['condition'] ?? '';

        // Preserve the original condition (properly decoded)
        if (!empty($originalCondition)) {
            // Handle JSON decoding similar to ConditionService to avoid escaped strings
            $conditionOriginal = $originalCondition;
            if (is_string($conditionOriginal)) {
                // Handle double-encoded JSON strings - decode to get proper JSON string
                $decoded = json_decode($conditionOriginal, true);
                if (is_string($decoded)) {
                    // If we got a string back, that's the unescaped JSON string we want
                    $conditionOriginal = $decoded;
                } elseif (is_array($decoded)) {
                    // If we got an array, encode it back to string
                    $conditionOriginal = json_encode($decoded);
                }
                // If decode failed or returned null, keep original
            } else {
                $conditionOriginal = json_encode($conditionOriginal);
            }
            $section['condition_original'] = $conditionOriginal;
        }

        // Perform normal interpolation. Conditions are stored as strings; any
        // other type is not a valid Mustache template input, so coerce to an
        // empty string instead of passing a non-string to interpolate().
        $conditionContent = is_string($originalCondition) ? $originalCondition : '';
        return $this->interpolate($conditionContent, ...$dataArrays);
    }

    /**
     * Render a Mustache template with partial support
     *
     * Allows rendering templates with reusable partials for better organization.
     *
     * @param string $template The Mustache template content
     * @param array<string, mixed> $context Data context for rendering
     * @param array<string, string> $partials Optional partial templates (name => content)
     * @return string The rendered template
     */
    public function renderTemplate(string $template, array $context = [], array $partials = []): string
    {
        try {
            if (!empty($partials)) {
                $loader = new \Mustache\Loader\ArrayLoader($partials);
                $this->mustache->setPartialsLoader($loader);
            }

            return $this->mustache->render($template, $context);

        } catch (\Exception $e) {
            $this->logError('Mustache template rendering failed: ' . $e->getMessage());
            return $template;
        }
    }

    /**
     * Add a custom Mustache helper
     *
     * Allows extending Mustache functionality with custom helpers for complex logic.
     *
     * @param string $name Helper name (used as {{name}} in templates)
     * @param callable $helper The helper function
     * @return self For method chaining
     */
    public function addHelper(string $name, callable $helper): self
    {
        $this->mustache->addHelper($name, $helper);
        return $this;
    }

    /**
     * Get the Mustache engine instance for advanced usage
     *
     * Allows direct access to the Mustache engine for advanced templating features.
     *
     * @return \Mustache\Engine The configured Mustache engine
     */
    public function getMustacheEngine(): \Mustache\Engine
    {
        return $this->mustache;
    }

    /**
     * Merge multiple data arrays into a single context
     *
     * Later arrays take precedence over earlier ones, allowing for layered configuration.
     *
     * @param array<array-key, array<array-key, mixed>> $dataArrays Arrays to merge
     * @return array<array-key, mixed> Merged context array
     */
    private function mergeDataContexts(array $dataArrays): array
    {
        $context = [];

        foreach ($dataArrays as $dataArray) {
            $context = array_merge($context, $dataArray);
        }

        return $context;
    }

    /**
     * Log interpolation errors
     *
     * Centralized error logging for interpolation failures.
     *
     * @param string $message Error message
     * @return void
     */
    private function logError(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);

            return;
        }

        // No PSR logger injected (e.g. when instantiated directly in tests):
        // fall back to error_log so interpolation errors are never silent.
        error_log($message);
    }
}
