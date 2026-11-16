<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\Core\LookupService;

/**
 * Builds the {@see EffectiveForm} of a form section from an {@see EffectivePage}:
 * the inputs below the form, by the names and rules the effective page stores.
 *
 * It follows the same convention as {@see FormFieldKeyResolver}: every section
 * below the form that has a non-empty `name` is an input, and its storage key
 * is `section_<id>`. What differs is the source: the published page (or, for an
 * authorized preview, the draft), never whatever the live tables hold.
 */
class EffectiveFormFactory
{
    /**
     * @param \Closure(array<array-key, mixed>): bool $conditionHolds whether a section row's condition holds for the visitor
     */
    public function build(EffectivePage $page, int $formSectionId, \Closure $conditionHolds): EffectiveForm
    {
        /** @var array<string, array<array-key, mixed>> $rowsByPath */
        $rowsByPath = [];
        foreach ($page->rows() as $row) {
            $path = $row['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $rowsByPath[$path] = $row;
            }
        }

        /** @var array<string, EffectiveFormInput> $inputs */
        $inputs = [];
        /** @var array<string, string> $keyByName */
        $keyByName = [];
        foreach ($page->rows() as $row) {
            $ids = EffectivePage::pathIds($row);
            $at = array_search($formSectionId, $ids, true);
            if ($at === false || $at === count($ids) - 1) {
                continue;
            }

            $sectionId = $ids[count($ids) - 1];
            $key = FormFieldKeyResolver::FIELD_KEY_PREFIX . $sectionId;
            if (isset($inputs[$key])) {
                continue; // another place of the same input: the first place decides
            }
            $raw = $page->rawFields($sectionId);
            $name = trim($this->content($raw, 'name'));
            if ($name === '') {
                continue; // a section below the form that is not an input
            }

            $visible = true;
            for ($depth = $at + 1; $depth < count($ids); ++$depth) {
                $place = $rowsByPath[implode(',', array_slice($ids, 0, $depth + 1))] ?? null;
                if ($place === null || !$conditionHolds($place)) {
                    $visible = false;
                    break;
                }
            }

            $style = is_string($row['style_name'] ?? null) ? $row['style_name'] : '';
            $inputs[$key] = $this->inputOf($style, $sectionId, $key, $name, $raw, $visible);
            $keyByName[$name] ??= $key;
        }

        return new EffectiveForm($formSectionId, $page->source, $inputs, $keyByName);
    }

    /**
     * @param array<string, array<int, string>> $raw
     */
    private function inputOf(string $style, int $sectionId, string $key, string $name, array $raw, bool $visible): EffectiveFormInput
    {
        $flag = fn (string $field): bool => in_array(strtolower(trim($this->content($raw, $field))), ['1', 'true', 'yes', 'on'], true);

        $typeInput = strtolower(trim($this->content($raw, 'type_input')));
        $valueType = FormFieldKeyResolver::valueTypeFor($style, $typeInput, $this->content($raw, 'web_datepicker_type'));

        $required = $flag('is_required') || ($style === 'slider' && $flag('web_slider_required'));
        $writable = !($flag('disabled') || $flag('readonly') || $flag('web_datepicker_readonly'));
        $isFile = $style === StyleNames::STYLE_FILE_INPUT;
        $translatable = $flag('translatable');

        $min = null;
        $max = null;
        $maxLength = null;
        $options = null;
        $creatable = false;
        $multiple = false;
        $maxSelections = null;
        $format = null;
        $minDate = null;
        $maxDate = null;
        $maxFiles = null;
        $accept = null;
        $onValue = null;
        $offValue = null;

        switch ($style) {
            case 'number-input':
                $min = $this->number($this->content($raw, 'web_numeric_min'));
                $max = $this->number($this->content($raw, 'web_numeric_max'));
                if ($this->content($raw, 'web_number_input_allow_negative') === '0') {
                    $min = max($min ?? 0.0, 0.0);
                }
                break;
            case 'slider':
                $min = $this->number($this->content($raw, 'web_numeric_min')) ?? 0.0;
                $max = $this->number($this->content($raw, 'web_numeric_max')) ?? 100.0;
                break;
            case 'rating':
                $min = 0.0;
                $max = $this->number($this->content($raw, 'web_rating_count')) ?? 5.0;
                break;
            case 'input':
                if ($typeInput === 'number') {
                    $min = $this->number($this->content($raw, 'min'));
                    $max = $this->number($this->content($raw, 'max'));
                } elseif ($typeInput === 'date') {
                    $minDate = $this->date($this->content($raw, 'min'));
                    $maxDate = $this->date($this->content($raw, 'max'));
                } elseif ($typeInput === 'email' || $typeInput === 'url') {
                    $format = $typeInput;
                }
                break;
            case 'text-input':
            case 'textarea':
                $maxLength = $this->positiveInt($this->content($raw, 'max_length'));
                break;
            case 'datepicker':
                $minDate = $this->date($this->content($raw, 'web_datepicker_min_date'));
                $maxDate = $this->date($this->content($raw, 'web_datepicker_max_date'));
                break;
            case 'select':
                $options = $this->optionValues($this->content($raw, 'options'));
                $multiple = $flag('is_multiple');
                $maxSelections = $this->positiveInt($this->content($raw, 'max'));
                break;
            case 'combobox':
                $options = $this->optionValues($this->content($raw, 'combobox_options'));
                $creatable = $flag('web_combobox_creatable');
                $multiple = $flag('web_combobox_multi_select');
                $maxSelections = $this->positiveInt($this->content($raw, 'web_multi_select_max_values'));
                break;
            case 'radio':
                $options = $this->optionValues($this->content($raw, 'radio_options'));
                break;
            case 'segmented-control':
                $options = $this->optionValues($this->content($raw, 'segmented_control_data'));
                break;
            case 'checkbox':
                $onValue = $this->orDefault($this->content($raw, 'checkbox_value'), '1');
                $offValue = '0';
                break;
            case 'switch':
                $onValue = $this->orDefault($this->content($raw, 'web_switch_on_value'), '1');
                $offValue = '0';
                break;
            case 'chip':
                $onValue = $this->orDefault($this->content($raw, 'chip_on_value'), '1');
                $offValue = $this->orDefault($this->content($raw, 'chip_off_value'), '0');
                break;
            case StyleNames::STYLE_FILE_INPUT:
                $multiple = $flag('web_file_input_multiple');
                $maxFiles = $this->positiveInt($this->content($raw, 'web_file_input_max_files'));
                $accept = $this->acceptTokens($this->content($raw, 'web_file_input_accept'));
                break;
            default:
                break;
        }

        // A single-file input keeps one file even when the author did not say so.
        if ($isFile && !$multiple && $maxFiles === null) {
            $maxFiles = 1;
        }

        return new EffectiveFormInput(
            sectionId: $sectionId,
            key: $key,
            name: $name,
            style: $style,
            valueType: $valueType === '' ? LookupService::DATA_COL_VALUE_TYPES_TEXT : $valueType,
            required: $required,
            writable: $writable,
            visible: $visible,
            isFile: $isFile,
            translatable: $translatable,
            multiple: $multiple,
            min: $min,
            max: $max,
            maxLength: $maxLength,
            options: $options,
            creatable: $creatable,
            maxSelections: $maxSelections,
            format: $format,
            minDate: $minDate,
            maxDate: $maxDate,
            maxFiles: $maxFiles,
            accept: $accept,
            onValue: $onValue,
            offValue: $offValue,
        );
    }

    /**
     * The stored content of a field: the property language first, then any language.
     *
     * @param array<string, array<int, string>> $raw
     */
    private function content(array $raw, string $field): string
    {
        $stored = $raw[$field] ?? [];
        if ($stored === []) {
            return '';
        }

        return $stored[1] ?? $stored[array_key_first($stored)];
    }

    private function number(string $text): ?float
    {
        $text = trim($text);

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    private function positiveInt(string $text): ?int
    {
        $text = trim($text);

        return $text !== '' && ctype_digit($text) && (int) $text > 0 ? (int) $text : null;
    }

    private function orDefault(string $text, string $default): string
    {
        return trim($text) === '' ? $default : $text;
    }

    /**
     * An ISO date (`2026-01-31`) from the first ten characters, or null when the
     * text is not one (a limit written as an expression is not a rule the server can apply).
     */
    private function date(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return substr($text, 0, 10);
    }

    /**
     * The values a choice input offers, from its stored JSON list (`[{"value": ...}, ...]` or `["a", "b"]`);
     * null when it lists none or the list is not in a known shape (then any value is accepted).
     *
     * @return list<string>|null
     */
    private function optionValues(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        $values = [];
        foreach ($decoded as $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : $item;
            if (!is_scalar($value)) {
                return null;
            }
            $values[] = (string) $value;
        }

        return $values;
    }

    /**
     * @return list<string>|null lower-case tokens (`.pdf`, `image/*`); null when the input accepts any allowed type
     */
    private function acceptTokens(string $text): ?array
    {
        $tokens = [];
        foreach (explode(',', strtolower($text)) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens === [] ? null : $tokens;
    }
}
