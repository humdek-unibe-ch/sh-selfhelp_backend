<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

/**
 * The inputs of one form section as the effective page defines them
 * ({@see EffectivePage}): the definition every write of the form is checked
 * against.
 *
 * A form that names no input at all (a table that is only written through
 * the API, or a form whose inputs were all removed) cannot say which fields it
 * accepts; it keeps accepting plain field keys, as before, and still refuses
 * every reserved key. A form that names inputs accepts those inputs and nothing else.
 */
final readonly class EffectiveForm
{
    /**
     * @param array<string, EffectiveFormInput> $inputsByKey `section_<id>` => input, in the order of the form
     * @param array<string, string> $keyByName input name => `section_<id>` (the first input wins a shared name)
     */
    public function __construct(
        public int $formSectionId,
        public string $source,
        public array $inputsByKey,
        public array $keyByName,
    ) {
    }

    public function hasInputs(): bool
    {
        return $this->inputsByKey !== [];
    }

    /**
     * The input a submitted name or storage key stands for.
     */
    public function resolve(string $nameOrKey): ?EffectiveFormInput
    {
        $key = $this->keyByName[$nameOrKey] ?? $nameOrKey;

        return $this->inputsByKey[$key] ?? null;
    }

    public function input(string $key): ?EffectiveFormInput
    {
        return $this->inputsByKey[$key] ?? null;
    }

    /**
     * The storage keys of the file inputs.
     *
     * @return list<string>
     */
    public function fileKeys(): array
    {
        $keys = [];
        foreach ($this->inputsByKey as $key => $input) {
            if ($input->isFile) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Storage key => the name the input is shown under (the label a column keeps).
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->inputsByKey as $key => $input) {
            $labels[$key] = $input->name;
        }

        return $labels;
    }
}
