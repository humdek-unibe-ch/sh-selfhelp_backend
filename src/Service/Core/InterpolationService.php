<?php

namespace App\Service\Core;

use App\Service\Core\BaseService;

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

    /**
     * Configuration options for the interpolation service
     */
    private array $config;

    /**
     * Constructor - Initialize Mustache engine
     *
     * @param array $config Configuration options:
     *   - custom_helpers: Array of custom Mustache helpers (optional)
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;

        // Create Mustache engine with explicit empty array
        $this->mustache = new \Mustache\Engine([]);

        // Add custom helpers if provided
        if (!empty($config['custom_helpers'])) {
            foreach ($config['custom_helpers'] as $name => $helper) {
                $this->mustache->addHelper($name, $helper);
            }
        }
    }

    /**
     * Perform Mustache rendering with error handling
     *
     * @param string $content The content containing {{variable}} patterns
     * @param array $dataArrays One or more arrays containing variable => value mappings
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
     * @param array $dataArrays One or more arrays containing variable => value mappings
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
     * @param array $contentArray Array containing content fields to interpolate
     * @param array $dataArrays One or more arrays containing variable => value mappings
     * @return array The content array with variables replaced
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
                $arrayValue = json_decode(json_encode($value), true);
                $interpolatedArray = $this->interpolateArray($arrayValue, ...$dataArrays);
                $result[$key] = json_decode(json_encode($interpolatedArray));
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
     * @param array &$section The section array (passed by reference to modify condition_original)
     * @param array $dataArrays One or more arrays containing variable => value mappings
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
                } elseif (is_array($decoded) || is_object($decoded)) {
                    // If we got an object/array, encode it back to string
                    $conditionOriginal = json_encode($decoded);
                }
                // If decode failed or returned null, keep original
            } else {
                $conditionOriginal = json_encode($conditionOriginal);
            }
            $section['condition_original'] = $conditionOriginal;
        }

        // Perform normal interpolation
        return $this->interpolate($originalCondition, ...$dataArrays);
    }

    /**
     * Render a Mustache template with partial support
     *
     * Allows rendering templates with reusable partials for better organization.
     *
     * @param string $template The Mustache template content
     * @param array $context Data context for rendering
     * @param array $partials Optional partial templates (name => content)
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
     * @param array $dataArrays Arrays to merge
     * @return array Merged context array
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
        // Use Symfony's logger if available, otherwise fallback to error_log
        if (method_exists($this, 'getLogger')) {
            $this->getLogger()->error($message);
        } else {
            error_log($message);
        }
    }
}
