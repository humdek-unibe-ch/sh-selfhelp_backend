<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\Effective\EffectivePage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which data tables a page reads (live-sync contract C6 `depends_on` and the C3
 * audience of a table notice).
 *
 * It reads the page the way {@see EffectivePage} holds it, published or draft,
 * so the answer follows the version the visitor is served. A table is read when
 * a section names it in `data_config` (by name), in the `data_table` field of a
 * list, data view, entry table or record style (by id), or when it is the table
 * named after the section of a form (a form without `data_table`). Whether the
 * read covers all users or only the signed-in user's own records decides who may
 * learn about a change: own records stay with their owner (rule 2.4, groups see).
 */
final class PageTableScanner implements ResetInterface
{
    /** Styles that name their table in the `data_table` field. */
    private const TABLE_FIELD_STYLES = [
        StyleNames::STYLE_ENTRY_TABLE,
        StyleNames::STYLE_ENTRY_LIST,
        StyleNames::STYLE_ENTRY_RECORD,
        StyleNames::STYLE_ENTRY_RECORD_FORM,
        StyleNames::STYLE_ENTRY_FORM_LIST,
        StyleNames::STYLE_DATA_VIEW,
    ];

    /** Styles whose own table is the one named after the section when `data_table` is empty. */
    private const OWN_TABLE_STYLES = [
        StyleNames::STYLE_FORM_RECORD,
        StyleNames::STYLE_ENTRY_RECORD_FORM,
        StyleNames::STYLE_ENTRY_FORM_LIST,
    ];

    /** @var array<string, int> table name => id, per request (found tables only) */
    private array $idByName = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function reset(): void
    {
        $this->idByName = [];
    }

    /**
     * @return array{any: list<int>, all_users: list<int>} table ids the page reads; and those it reads for every user
     */
    public function scan(EffectivePage $page): array
    {
        /** @var array<string, bool> $refs `id:<n>` or `name:<n>` => read for all users (true wins) */
        $refs = [];
        $note = static function (string $ref, bool $allUsers) use (&$refs): void {
            $refs[$ref] = ($refs[$ref] ?? false) || $allUsers;
        };

        foreach ($page->rows() as $row) {
            $sectionId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            if ($sectionId <= 0) {
                continue;
            }

            foreach ($this->dataConfigTables($row['data_config'] ?? null) as $name => $allUsers) {
                $note('name:' . $name, $allUsers);
            }

            $style = is_string($row['style_name'] ?? null) ? $row['style_name'] : '';
            $isFormRecord = $style === StyleNames::STYLE_FORM_RECORD;
            if (!in_array($style, self::TABLE_FIELD_STYLES, true) && !$isFormRecord) {
                continue;
            }

            $own = $this->readsOwnRecordsOnly($style, $page->fieldContent($sectionId, 'own_entries_only'));
            $tableText = trim($page->fieldContent($sectionId, 'data_table'));
            if (ctype_digit($tableText) && (int) $tableText > 0) {
                $note('id:' . (int) $tableText, !$own);
            } elseif (in_array($style, self::OWN_TABLE_STYLES, true)) {
                // A form prefills from its own table: the one named after the section.
                $note('name:' . $sectionId, !$isFormRecord && !$own);
            }
        }

        return $this->resolve($refs);
    }

    /**
     * @return array<string, bool> table name => read for every user (`current_user: false`)
     */
    private function dataConfigTables(mixed $dataConfig): array
    {
        if (is_string($dataConfig)) {
            $dataConfig = json_decode($dataConfig, true);
        }
        if (!is_array($dataConfig)) {
            return [];
        }

        $tables = [];
        foreach ($dataConfig as $config) {
            if (!is_array($config) || !is_string($config['table'] ?? null) || $config['table'] === '') {
                continue;
            }
            $allUsers = ($config['current_user'] ?? true) === false;
            $tables[$config['table']] = ($tables[$config['table']] ?? false) || $allUsers;
        }

        return $tables;
    }

    /**
     * A list of records shows only the caller's own unless the author turned
     * that off. A data view defaults to all records, everything else to own.
     */
    private function readsOwnRecordsOnly(string $style, string $stored): bool
    {
        $text = strtolower(trim($stored));
        if ($text === '') {
            return $style !== StyleNames::STYLE_DATA_VIEW;
        }

        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, bool> $refs
     * @return array{any: list<int>, all_users: list<int>}
     */
    private function resolve(array $refs): array
    {
        $names = [];
        foreach (array_keys($refs) as $ref) {
            if (str_starts_with($ref, 'name:')) {
                $names[] = substr($ref, 5);
            }
        }
        $this->loadNames($names);

        $any = [];
        $allUsers = [];
        foreach ($refs as $ref => $isAllUsers) {
            $id = str_starts_with($ref, 'id:') ? (int) substr($ref, 3) : ($this->idByName[substr($ref, 5)] ?? null);
            if ($id === null || $id <= 0) {
                continue;
            }
            $any[$id] = $id;
            if ($isAllUsers) {
                $allUsers[$id] = $id;
            }
        }

        $any = array_values($any);
        $allUsers = array_values($allUsers);
        sort($any);
        sort($allUsers);

        return ['any' => $any, 'all_users' => $allUsers];
    }

    /**
     * @param list<string> $names
     */
    private function loadNames(array $names): void
    {
        // Only found ids are remembered: a table that does not exist yet may be
        // created later in the same process (the first save of a form creates it).
        $missing = array_values(array_filter(array_unique($names), fn (string $n): bool => !isset($this->idByName[$n])));
        if ($missing === []) {
            return;
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, name FROM data_tables WHERE name IN (?)',
            [$missing],
            [ArrayParameterType::STRING],
        );
        foreach ($rows as $row) {
            if (is_string($row['name'] ?? null) && is_numeric($row['id'] ?? null)) {
                $this->idByName[$row['name']] = (int) $row['id'];
            }
        }
    }
}
