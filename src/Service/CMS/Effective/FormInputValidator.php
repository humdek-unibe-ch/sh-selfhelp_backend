<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

use App\Entity\Language;
use App\Exception\ServiceException;
use App\Service\CMS\DataColumnService;
use App\Service\CMS\DataQuery\DataColValueType;
use App\Service\CMS\DataRevision\RestorePlan;
use App\Service\Core\LookupService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place that decides whether a payload may be written into a form's table.
 *
 * A form payload is untrusted. The server resolves what is writable from the
 * {@see EffectiveForm} (the inputs of the page as it is served), never from the
 * payload: the person can change the value of an input they are allowed to see
 * and nothing else. In particular a payload can never set the owner of a record,
 * its trigger type or any other row metadata, and it cannot name a column the
 * form does not have.
 *
 * The same rules answer the three ways a value gets into a record: a form
 * submission ({@see self::accept()}), a quick action that assigns values
 * ({@see self::assertAssignable()}) and the restore of an earlier version
 * ({@see self::assertRestorable()}). A refusal changes nothing: it is thrown
 * before an upload is stored, a row is written or an action is scheduled.
 */
class FormInputValidator
{
    /** Keys of the request itself and of the row, never a value of the form. */
    private const PROTECTED_KEYS = ['timestamp', 'version', 'expected_version', 'update_based_on', 'page_id', 'section_id', 'preview', '__id_sections'];

    public function __construct(
        private readonly DataColumnService $columns,
    ) {
    }

    /**
     * Whether a key can never be a value of a form: the row metadata (owner, trigger
     * type, record number, ...), the request's own fields and the runtime option labels.
     */
    public function isProtectedKey(string $key): bool
    {
        return $this->columns->isReservedKey($key) || in_array($key, self::PROTECTED_KEYS, true);
    }

    /**
     * Check a submitted payload against the effective form and return what may be written.
     *
     * @param array<string, mixed> $submitted name or storage key => value, as the client sent it
     * @param array<string, list<UploadedFile>> $uploads field name => the files the request carries for it
     * @param bool $isCreate a new record: required inputs must be present
     * @return array{
     *   data: array<string, mixed>,
     *   labels: array<string, string>,
     *   files: array<string, list<UploadedFile>>,
     *   file_ops: array<string, array{remove: bool, remove_indexes: list<int>}>
     * }
     *         `data` by storage key (plain keys of a form without inputs stay as they are), `labels` key => input name,
     *         `files` storage key => uploaded files,
     *         `file_ops` storage key => explicit keep/remove instruction for an update (contract 4)
     * @throws ServiceException 400 a protected, unknown or doubled field, 422 a value that breaks a rule
     */
    public function accept(EffectiveForm $form, array $submitted, array $uploads, bool $isCreate): array
    {
        $protected = [];
        $unknown = [];
        $badFiles = [];
        /** @var array<string, mixed> $data */
        $data = [];
        /** @var array<string, string> $labels */
        $labels = [];
        /** @var array<string, string> $sources storage key => the name it arrived under */
        $sources = [];
        /** @var array<string, array{remove: bool, remove_indexes: list<int>}> $fileOps */
        $fileOps = [];

        foreach ($submitted as $name => $value) {
            $name = (string) $name;
            if ($this->isProtectedKey($name)) {
                $protected[] = $name;
                continue;
            }
            $input = $form->resolve($name);
            if ($input === null) {
                if ($form->hasInputs()) {
                    $unknown[] = $name;
                    continue;
                }
                // A form that names no input keeps its plain keys (see EffectiveForm).
                $data[$name] = $value;
                continue;
            }
            if (isset($sources[$input->key])) {
                $protected[] = $name; // the same input under two names
                continue;
            }
            $sources[$input->key] = $name;
            if ($input->isFile) {
                // Empty = keep existing (on update). An object with remove / remove_indexes
                // is the explicit clear instruction (contract 4). Anything else is refused:
                // a client must never point a record at a file it did not upload.
                if ($this->isEmpty($value)) {
                    continue;
                }
                $op = $this->fileOpOf($value);
                if ($op === null) {
                    $badFiles[] = $name;
                    continue;
                }
                $fileOps[$input->key] = $op;
                $labels[$input->key] = $input->name;
                continue;
            }
            $data[$input->key] = $value;
            $labels[$input->key] = $input->name;
        }

        if ($protected !== []) {
            throw new ServiceException(
                sprintf('The field "%s" is not a value a form can set.', $protected[0]),
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'protected_field', 'fields' => $protected],
            );
        }
        if ($badFiles !== []) {
            throw new ServiceException(
                sprintf('The file field "%s" takes an uploaded file, not a value.', $badFiles[0]),
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'invalid_file_value', 'fields' => $badFiles],
            );
        }

        /** @var array<string, list<UploadedFile>> $files */
        $files = [];
        foreach ($uploads as $name => $list) {
            $input = $form->resolve($name);
            if ($input === null || !$input->isFile) {
                $unknown[] = $name;
                continue;
            }
            if (isset($files[$input->key])) {
                $unknown[] = $name;
                continue;
            }
            $files[$input->key] = $list;
        }
        if ($unknown !== []) {
            throw new ServiceException(
                sprintf('The field "%s" is not an input of this form.', $unknown[0]),
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'unknown_field', 'fields' => $unknown],
            );
        }

        $errors = [];
        foreach ($data as $key => $value) {
            $input = $form->input($key);
            if ($input === null) {
                continue; // a plain key of a form without inputs
            }
            if (!$input->visible) {
                $errors[] = $this->error($input, 'not_available', 'This field is not available.');
                continue;
            }
            if (!$input->writable) {
                $errors[] = $this->error($input, 'read_only', 'This field cannot be changed.');
                continue;
            }
            array_push($errors, ...$this->valueErrors($input, $value, true));
        }

        foreach ($files as $key => $list) {
            $input = $form->input($key);
            if ($input === null) {
                continue;
            }
            if (!$input->visible) {
                $errors[] = $this->error($input, 'not_available', 'This field is not available.');
                continue;
            }
            if (!$input->writable) {
                $errors[] = $this->error($input, 'read_only', 'This field cannot be changed.');
                continue;
            }
            array_push($errors, ...$this->fileErrors($input, $list));
        }

        if ($isCreate) {
            foreach ($form->inputsByKey as $key => $input) {
                if (!$input->required || !$input->visible || !$input->writable) {
                    continue;
                }
                $present = $input->isFile
                    ? ($files[$key] ?? []) !== []
                    : array_key_exists($key, $data) && !$this->isEmpty($data[$key]);
                if (!$present) {
                    $errors[] = $this->error($input, 'required', 'This field is required.');
                }
            }
        }

        if ($errors !== []) {
            throw $this->invalid($errors);
        }

        foreach ($data as $key => $value) {
            $input = $form->input($key);
            if ($input !== null) {
                $data[$key] = $this->storedValue($input, $value);
            }
        }

        return ['data' => $data, 'labels' => $labels, 'files' => $files, 'file_ops' => $fileOps];
    }

    /**
     * Explicit file-field instruction on update: clear all, or drop named indexes.
     * Multipart sends the object as a JSON string; JSON requests send the object.
     *
     * @return array{remove: bool, remove_indexes: list<int>}|null
     */
    private function fileOpOf(mixed $value): ?array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed[0] !== '{') {
                return null;
            }
            try {
                $decoded = json_decode($trimmed, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            $value = $decoded;
        }
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }
        $remove = ($value['remove'] ?? false) === true;
        $indexes = [];
        if (array_key_exists('remove_indexes', $value)) {
            if (!is_array($value['remove_indexes'])) {
                return null;
            }
            foreach ($value['remove_indexes'] as $index) {
                if (!is_int($index) && !(is_string($index) && ctype_digit($index))) {
                    return null;
                }
                $indexes[] = (int) $index;
            }
        }
        $unknown = array_diff(array_keys($value), ['remove', 'remove_indexes']);
        if ($unknown !== [] || (!$remove && $indexes === [])) {
            return null;
        }

        return ['remove' => $remove, 'remove_indexes' => array_values(array_unique($indexes))];
    }

    /**
     * The values a quick action assigns must be values the form would accept for those columns.
     * Whether an input is shown or read-only does not matter here: the author, not the visitor, chose the value.
     *
     * @param array<string, string> $assignments column key => stored text
     * @throws ServiceException 422 when a value breaks a rule of the input that feeds the column
     */
    public function assertAssignable(EffectiveForm $form, array $assignments): void
    {
        $errors = [];
        foreach ($assignments as $key => $text) {
            $input = $form->input($key);
            if ($input === null || $input->isFile) {
                continue;
            }
            array_push($errors, ...$this->valueErrors($input, $text, true));
        }
        if ($errors !== []) {
            throw $this->invalid($errors);
        }
    }

    /**
     * What a restore would write must satisfy the form as it is now. A value of an old version that the current
     * input refuses (a rule changed since), the removal of a field that is required now, and a change to an
     * input the person cannot change today (read-only, or hidden by its condition) block the whole restore.
     *
     * The blockers have the shape of the other restore blockers ({@see RestorePlan::$blockers}) with the reason
     * `invalid_value` and the broken rule in `detail`, so every client already shows them.
     *
     * @return list<array{field_key: string, field_label: ?string, language_id: int, reason: string, detail: ?string}>
     */
    public function restoreBlockers(EffectiveForm $form, RestorePlan $plan): array
    {
        $blockers = [];
        foreach ($plan->changes as $change) {
            $input = $form->input($change['field_key']);
            if ($input === null) {
                continue; // a column the form has no input for: the list's own columns follow the data table's rules
            }

            $details = [];
            if (!$input->visible) {
                $details[] = 'This field is not available.';
            } elseif (!$input->writable) {
                $details[] = 'This field cannot be changed.';
            } elseif (!$input->isFile) {
                if ($change['action'] === 'remove') {
                    if ($input->required && $change['language_id'] === Language::INDEPENDENT_ID) {
                        $details[] = 'This field is required now, so the earlier version without it cannot be restored.';
                    }
                } else {
                    foreach ($this->valueErrors($input, $change['after'] ?? '', true) as $error) {
                        $details[] = $error['message'];
                    }
                }
            }

            foreach ($details as $detail) {
                $blockers[] = [
                    'field_key' => $change['field_key'],
                    'field_label' => $change['field_label'] ?? $input->name,
                    'language_id' => $change['language_id'],
                    'reason' => RestorePlan::REASON_INVALID_VALUE,
                    'detail' => $detail,
                ];
            }
        }

        return $blockers;
    }

    /**
     * @throws ServiceException 422 `restore_blocked` with the blockers; nothing has been written
     */
    public function assertRestorable(EffectiveForm $form, RestorePlan $plan): void
    {
        $blockers = $this->restoreBlockers($form, $plan);
        if ($blockers !== []) {
            throw new ServiceException(
                sprintf('Version %d cannot be restored as it is: some of its values no longer fit this form.', $plan->fromVersion),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['reason' => 'restore_blocked', 'record_id' => $plan->recordId, 'from_version' => $plan->fromVersion, 'blockers' => $blockers],
            );
        }
    }

    /**
     * @return list<array{field: string, key: string, rule: string, message: string}>
     */
    private function valueErrors(EffectiveFormInput $input, mixed $value, bool $checkRequired): array
    {
        $parsed = $this->itemsOf($input, $value);
        if ($parsed === null) {
            return [$this->error($input, 'invalid', 'This value is not valid.')];
        }
        $items = $parsed['items'];

        $errors = [];
        $nonEmpty = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
        if ($nonEmpty === []) {
            if ($checkRequired && $input->required) {
                $errors[] = $this->error($input, 'required', 'This field is required.');
            }

            return $errors;
        }

        if ($input->maxSelections !== null && count($nonEmpty) > $input->maxSelections) {
            $errors[] = $this->error($input, 'too_many', sprintf('Choose at most %d.', $input->maxSelections));
        }
        if (!$input->multiple && !$parsed['translations'] && count($nonEmpty) > 1) {
            $errors[] = $this->error($input, 'too_many', 'Only one value is allowed.');
        }

        foreach ($nonEmpty as $item) {
            array_push($errors, ...$this->itemErrors($input, $item));
        }

        return $errors;
    }

    /**
     * @return list<array{field: string, key: string, rule: string, message: string}>
     */
    private function itemErrors(EffectiveFormInput $input, string $item): array
    {
        $errors = [];
        $isOnOff = $input->onValue !== null && ($item === $input->onValue || $item === $input->offValue);

        if (!$isOnOff && !DataColValueType::reads($input->valueType, $item)) {
            $errors[] = $this->error($input, 'type', $this->typeMessage($input->valueType));

            return $errors; // the other rules assume a value of the right type
        }

        if (($input->min !== null || $input->max !== null) && is_numeric($item)) {
            $number = (float) $item;
            if ($input->min !== null && $number < $input->min) {
                $errors[] = $this->error($input, 'min', sprintf('The value must be at least %s.', $this->numberText($input->min)));
            }
            if ($input->max !== null && $number > $input->max) {
                $errors[] = $this->error($input, 'max', sprintf('The value must be at most %s.', $this->numberText($input->max)));
            }
        }
        if ($input->maxLength !== null && mb_strlen($item) > $input->maxLength) {
            $errors[] = $this->error($input, 'max_length', sprintf('At most %d characters.', $input->maxLength));
        }
        if ($input->format === 'email' && filter_var($item, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $this->error($input, 'format', 'This is not an email address.');
        }
        if ($input->format === 'url' && filter_var($item, FILTER_VALIDATE_URL) === false) {
            $errors[] = $this->error($input, 'format', 'This is not a web address.');
        }
        if ($input->options !== null && !$input->creatable && !in_array($item, $input->options, true)) {
            $errors[] = $this->error($input, 'option', 'This is not one of the offered choices.');
        }
        if (($input->minDate !== null || $input->maxDate !== null) && strlen($item) >= 10) {
            $day = substr($item, 0, 10);
            if ($input->minDate !== null && $day < $input->minDate) {
                $errors[] = $this->error($input, 'min_date', sprintf('The date must not be before %s.', $input->minDate));
            }
            if ($input->maxDate !== null && $day > $input->maxDate) {
                $errors[] = $this->error($input, 'max_date', sprintf('The date must not be after %s.', $input->maxDate));
            }
        }

        return $errors;
    }

    /**
     * @param list<UploadedFile> $files
     * @return list<array{field: string, key: string, rule: string, message: string}>
     */
    private function fileErrors(EffectiveFormInput $input, array $files): array
    {
        $errors = [];
        if ($input->maxFiles !== null && count($files) > $input->maxFiles) {
            $errors[] = $this->error($input, 'too_many', sprintf('At most %d files.', $input->maxFiles));
        }
        if ($input->accept !== null) {
            foreach ($files as $file) {
                if (!$this->accepted($file, $input->accept)) {
                    $errors[] = $this->error($input, 'file_type', 'This type of file is not accepted here.');
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $accept
     */
    private function accepted(UploadedFile $file, array $accept): bool
    {
        $extension = '.' . strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getClientMimeType());
        foreach ($accept as $token) {
            if (str_starts_with($token, '.') && $token === $extension) {
                return true;
            }
            if (str_ends_with($token, '/*') && str_starts_with($mime, substr($token, 0, -1))) {
                return true;
            }
            if (str_contains($token, '/') && !str_ends_with($token, '/*') && $token === $mime) {
                return true;
            }
        }

        return false;
    }

    /**
     * What is stored for an accepted value. The stored form of several choices is one comma separated text, which is
     * what the web and the mobile clients send; a list is turned into it, so it is not lost on the way into a cell.
     * Everything else is stored as it was sent (a per-language list becomes one cell per language).
     */
    private function storedValue(EffectiveFormInput $input, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $parsed = $this->itemsOf($input, $value);
        if ($parsed === null || $parsed['translations']) {
            return $value;
        }

        return implode(',', $parsed['items']);
    }

    /**
     * The values a submitted value holds, as text: one for a plain value, several for a list and for a per-language
     * list (`[{language_id, value}, ...]`, which every text input accepts: one cell is stored per language).
     * Null when the value has no usable shape.
     *
     * @return array{items: list<string>, translations: bool}|null
     */
    private function itemsOf(EffectiveFormInput $input, mixed $value): ?array
    {
        if ($value === null) {
            return ['items' => [], 'translations' => false];
        }
        if (is_bool($value)) {
            return ['items' => [$value ? '1' : '0'], 'translations' => false];
        }
        if (is_int($value) || is_float($value)) {
            return ['items' => [(string) $value], 'translations' => false];
        }
        if (is_string($value)) {
            $text = trim($value);
            if ($input->multiple && $text !== '' && $text[0] === '[' && str_ends_with($text, ']')) {
                $decoded = json_decode($text, true);
                if (is_array($decoded) && array_is_list($decoded)) {
                    $items = $this->scalarsOf($decoded);

                    return $items === null ? null : ['items' => $items, 'translations' => false];
                }
            }
            if ($input->multiple && str_contains($value, ',') && !in_array($value, $input->options ?? [], true)) {
                // Several choices travel as one comma separated text, unless the text is itself one offered choice.
                $items = array_values(array_filter(
                    array_map('trim', explode(',', $value)),
                    static fn (string $item): bool => $item !== '',
                ));

                return ['items' => $items, 'translations' => false];
            }

            return ['items' => [$value], 'translations' => false];
        }
        if (!is_array($value)) {
            return null;
        }
        if ($value === []) {
            return ['items' => [], 'translations' => false];
        }

        if (isset($value[0]) && is_array($value[0]) && array_key_exists('language_id', $value[0])) {
            $items = [];
            foreach ($value as $entry) {
                if (!is_array($entry) || !is_scalar($entry['value'] ?? null)) {
                    return null;
                }
                $items[] = (string) $entry['value'];
            }

            return ['items' => $items, 'translations' => true];
        }

        $items = array_is_list($value) ? $this->scalarsOf($value) : null;

        return $items === null ? null : ['items' => $items, 'translations' => false];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>|null
     */
    private function scalarsOf(array $values): ?array
    {
        $items = [];
        foreach ($values as $item) {
            if (is_bool($item)) {
                $items[] = $item ? '1' : '0';
            } elseif (is_scalar($item)) {
                $items[] = (string) $item;
            } else {
                return null;
            }
        }

        return $items;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '' || trim($value) === '{}' || trim($value) === '[]';
        }

        return false;
    }

    private function typeMessage(string $valueType): string
    {
        return match ($valueType) {
            LookupService::DATA_COL_VALUE_TYPES_NUMBER => 'This is not a number.',
            LookupService::DATA_COL_VALUE_TYPES_DATE => 'This is not a valid date (YYYY-MM-DD).',
            LookupService::DATA_COL_VALUE_TYPES_DATETIME => 'This is not a valid date and time.',
            LookupService::DATA_COL_VALUE_TYPES_BOOLEAN => 'This is not a yes/no value.',
            default => 'This value is not valid.',
        };
    }

    private function numberText(float $number): string
    {
        return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
    }

    /**
     * @return array{field: string, key: string, rule: string, message: string}
     */
    private function error(EffectiveFormInput $input, string $rule, string $message): array
    {
        return ['field' => $input->name, 'key' => $input->key, 'rule' => $rule, 'message' => $message];
    }

    /**
     * @param list<array{field: string, key: string, rule: string, message: string}> $errors
     */
    private function invalid(array $errors): ServiceException
    {
        return new ServiceException(
            'The entry could not be saved: ' . $errors[0]['field'] . ': ' . $errors[0]['message'],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['reason' => 'invalid_form_data', 'errors' => $errors],
        );
    }
}
