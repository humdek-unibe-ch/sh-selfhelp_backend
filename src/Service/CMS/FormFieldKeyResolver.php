<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\SectionsFieldsTranslation;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps a core CMS form's human input *names* to the immutable storage key that
 * identifies the input section owning each name (issue #56).
 *
 * The contract for `data_cols.field_key` is per data source (see
 * {@see DataColumnService}). For CORE CMS forms the stable key is
 * `section_<input section id>`: every input is a section, the section id never
 * changes, so a later rename of the input's `name` only moves the column's
 * `display_name` instead of forking a brand-new column. This resolver is the
 * single place that derives that mapping, by walking the form section's
 * descendant input sections (the same sections whose named `<input>`s the
 * renderer emits inside the form).
 *
 * The `section_` prefix keeps the key a non-numeric string so it never collides
 * with PHP/JSON numeric-array-key coercion as it flows through the save and read
 * pipelines (a bare numeric id would silently become an int array key).
 *
 * It deliberately returns an EMPTY map for any table that is not a numeric form
 * section id — SurveyJS writes to `sh2_surveyjs_<id>` (key = `question.name`),
 * `UserValidationController` writes to `user_validation_inputs`, etc. Those
 * sources keep their own submitted keys untouched.
 *
 * Maps are memoised per request (section structure cannot change mid-request).
 */
class FormFieldKeyResolver
{
    /** Stable field-key prefix for core form input sections. */
    public const FIELD_KEY_PREFIX = 'section_';

    /**
     * Per-request memo. The key is the table name; a numeric form section id
     * ("123") is coerced to an int array key by PHP, hence the int|string key.
     *
     * @var array<int|string, array{nameToKey: array<string,string>, keyToName: array<string,string>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * human input name => immutable field key (`section_<id>`) for the form table.
     *
     * @return array<string, string>
     */
    public function getNameToFieldKey(string $tableName): array
    {
        return $this->buildMap($tableName)['nameToKey'];
    }

    /**
     * immutable field key (`section_<id>`) => current human input name.
     *
     * @return array<string, string>
     */
    public function getFieldKeyToName(string $tableName): array
    {
        return $this->buildMap($tableName)['keyToName'];
    }

    /**
     * @return array{nameToKey: array<string,string>, keyToName: array<string,string>}
     */
    private function buildMap(string $tableName): array
    {
        if (isset($this->cache[$tableName])) {
            return $this->cache[$tableName];
        }

        $empty = ['nameToKey' => [], 'keyToName' => []];

        // Only numeric form *section ids* carry the section-id key contract.
        if ($tableName === '' || !ctype_digit($tableName)) {
            return $this->cache[$tableName] = $empty;
        }

        $descendantIds = $this->descendantSectionIds((int) $tableName);
        if ($descendantIds === []) {
            return $this->cache[$tableName] = $empty;
        }

        /** @var list<array{sectionId: mixed, content: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(t.section) AS sectionId', 't.content AS content')
            ->from(SectionsFieldsTranslation::class, 't')
            ->join('t.field', 'f')
            ->where('t.section IN (:ids)')
            ->andWhere('f.name = :nameField')
            ->setParameter('ids', $descendantIds)
            ->setParameter('nameField', 'name')
            ->getQuery()
            ->getResult();

        $nameToKey = [];
        $keyToName = [];
        foreach ($rows as $row) {
            $sectionId = is_numeric($row['sectionId']) ? (int) $row['sectionId'] : 0;
            $content = is_scalar($row['content']) ? (string) $row['content'] : '';
            if ($sectionId <= 0 || $content === '') {
                continue;
            }
            $fieldKey = self::FIELD_KEY_PREFIX . $sectionId;
            // First name wins per section; first section wins per name (CMS
            // convention keeps input names unique within a form).
            if (!isset($keyToName[$fieldKey])) {
                $keyToName[$fieldKey] = $content;
            }
            if (!isset($nameToKey[$content])) {
                $nameToKey[$content] = $fieldKey;
            }
        }

        return $this->cache[$tableName] = ['nameToKey' => $nameToKey, 'keyToName' => $keyToName];
    }

    /**
     * All descendant section ids of the form section (recursive), resolved with
     * a single recursive CTE over the section hierarchy.
     *
     * @return list<int>
     */
    private function descendantSectionIds(int $formSectionId): array
    {
        $sql = 'WITH RECURSIVE descendants (id) AS ('
            . ' SELECT id_child_section FROM rel_sections_hierarchy WHERE id_parent_section = :root'
            . ' UNION'
            . ' SELECT h.id_child_section FROM rel_sections_hierarchy h'
            . ' INNER JOIN descendants d ON h.id_parent_section = d.id'
            . ' ) SELECT id FROM descendants';

        $values = $this->entityManager->getConnection()
            ->executeQuery($sql, ['root' => $formSectionId], ['root' => ParameterType::INTEGER])
            ->fetchFirstColumn();

        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
