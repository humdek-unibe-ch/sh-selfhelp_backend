<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\SectionsFieldsTranslation;
use App\Service\CMS\Common\StyleNames;
use App\Service\Core\LookupService;
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
     * @var array<int|string, array{nameToKey: array<string,string>, keyToName: array<string,string>, fileKeys: array<string,true>, valueTypes: array<string,string>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Drop the memo of one table. Only a caller that changes the form's inputs
     * within the request (an import creating them) needs this, to read the map
     * of the inputs it has just made.
     */
    public function forget(string $tableName): void
    {
        unset($this->cache[$tableName]);
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
     * immutable field key (`section_<id>`) => declared value type of the input
     * (`number`, `date`, `datetime`, `boolean`). Inputs that hold plain text are
     * left out, so a missing key means `text`. The type follows the input's style
     * and type field; it tells the data query engine how to compare and sort the
     * stored text.
     *
     * @return array<string, string>
     */
    public function getFieldKeyToValueType(string $tableName): array
    {
        return $this->buildMap($tableName)['valueTypes'];
    }

    /**
     * The value type a form input style stores. Pure and static so the CMS
     * editor and the data layer agree on one mapping.
     *
     * @param string|null $styleName the input section's style
     * @param string|null $typeInput the `type_input` field of an `input` style
     * @param string|null $datepickerType the `web_datepicker_type` field of a `datepicker` style (`date` when unset)
     */
    public static function valueTypeFor(?string $styleName, ?string $typeInput, ?string $datepickerType): string
    {
        return match ($styleName) {
            'number-input', 'slider', 'rating' => LookupService::DATA_COL_VALUE_TYPES_NUMBER,
            'checkbox', 'switch' => LookupService::DATA_COL_VALUE_TYPES_BOOLEAN,
            'input' => match (strtolower(trim((string) $typeInput))) {
                'number' => LookupService::DATA_COL_VALUE_TYPES_NUMBER,
                'date' => LookupService::DATA_COL_VALUE_TYPES_DATE,
                'checkbox' => LookupService::DATA_COL_VALUE_TYPES_BOOLEAN,
                default => LookupService::DATA_COL_VALUE_TYPES_TEXT,
            },
            'datepicker' => match (strtolower(trim((string) $datepickerType))) {
                'datetime' => LookupService::DATA_COL_VALUE_TYPES_DATETIME,
                'time' => LookupService::DATA_COL_VALUE_TYPES_TEXT,
                default => LookupService::DATA_COL_VALUE_TYPES_DATE,
            },
            default => LookupService::DATA_COL_VALUE_TYPES_TEXT,
        };
    }

    /**
     * True when `$nameOrKey` (human input name or `section_<id>` key) belongs to
     * a `file-input` section of the form table.
     */
    public function isFileInput(string $tableName, string $nameOrKey): bool
    {
        $map = $this->buildMap($tableName);
        $key = $map['nameToKey'][$nameOrKey] ?? $nameOrKey;

        return isset($map['fileKeys'][$key]);
    }

    /**
     * @return array{nameToKey: array<string,string>, keyToName: array<string,string>, fileKeys: array<string,true>, valueTypes: array<string,string>}
     */
    private function buildMap(string $tableName): array
    {
        if (isset($this->cache[$tableName])) {
            return $this->cache[$tableName];
        }

        $empty = ['nameToKey' => [], 'keyToName' => [], 'fileKeys' => [], 'valueTypes' => []];

        // Only numeric form *section ids* carry the section-id key contract.
        if ($tableName === '' || !ctype_digit($tableName)) {
            return $this->cache[$tableName] = $empty;
        }

        $descendantIds = $this->descendantSectionIds((int) $tableName);
        if ($descendantIds === []) {
            return $this->cache[$tableName] = $empty;
        }

        /** @var list<array{sectionId: mixed, content: mixed, styleName: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(t.section) AS sectionId', 't.content AS content', 'st.name AS styleName')
            ->from(SectionsFieldsTranslation::class, 't')
            ->join('t.field', 'f')
            ->join('t.section', 's')
            ->leftJoin('s.style', 'st')
            ->where('t.section IN (:ids)')
            ->andWhere('f.name = :nameField')
            ->setParameter('ids', $descendantIds)
            ->setParameter('nameField', 'name')
            ->getQuery()
            ->getResult();

        $typeFields = $this->typeFieldValues($descendantIds);

        $nameToKey = [];
        $keyToName = [];
        $fileKeys = [];
        $valueTypes = [];
        foreach ($rows as $row) {
            $sectionId = is_numeric($row['sectionId']) ? (int) $row['sectionId'] : 0;
            $content = is_scalar($row['content']) ? (string) $row['content'] : '';
            if ($sectionId <= 0 || $content === '') {
                continue;
            }
            $fieldKey = self::FIELD_KEY_PREFIX . $sectionId;
            if ($row['styleName'] === StyleNames::STYLE_FILE_INPUT) {
                $fileKeys[$fieldKey] = true;
            }
            $valueType = self::valueTypeFor(
                is_string($row['styleName']) ? $row['styleName'] : null,
                $typeFields['type_input'][$sectionId] ?? null,
                $typeFields['web_datepicker_type'][$sectionId] ?? null,
            );
            if ($valueType !== LookupService::DATA_COL_VALUE_TYPES_TEXT) {
                $valueTypes[$fieldKey] = $valueType;
            }
            // First name wins per section; first section wins per name (CMS
            // convention keeps input names unique within a form).
            if (!isset($keyToName[$fieldKey])) {
                $keyToName[$fieldKey] = $content;
            }
            if (!isset($nameToKey[$content])) {
                $nameToKey[$content] = $fieldKey;
            }
        }

        return $this->cache[$tableName] = ['nameToKey' => $nameToKey, 'keyToName' => $keyToName, 'fileKeys' => $fileKeys, 'valueTypes' => $valueTypes];
    }

    /**
     * The type-defining property fields of the given sections: field name =>
     * section id => content.
     *
     * @param list<int> $sectionIds
     * @return array{type_input: array<int,string>, web_datepicker_type: array<int,string>}
     */
    private function typeFieldValues(array $sectionIds): array
    {
        /** @var list<array{sectionId: mixed, fieldName: mixed, content: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(t.section) AS sectionId', 'f.name AS fieldName', 't.content AS content')
            ->from(SectionsFieldsTranslation::class, 't')
            ->join('t.field', 'f')
            ->where('t.section IN (:ids)')
            ->andWhere('f.name IN (:names)')
            ->setParameter('ids', $sectionIds)
            ->setParameter('names', ['type_input', 'web_datepicker_type'])
            ->getQuery()
            ->getResult();

        $values = ['type_input' => [], 'web_datepicker_type' => []];
        foreach ($rows as $row) {
            $sectionId = is_numeric($row['sectionId']) ? (int) $row['sectionId'] : 0;
            $fieldName = $row['fieldName'];
            if ($sectionId <= 0 || !is_scalar($row['content']) || !in_array($fieldName, ['type_input', 'web_datepicker_type'], true)) {
                continue;
            }
            if (!isset($values[$fieldName][$sectionId])) {
                $values[$fieldName][$sectionId] = (string) $row['content'];
            }
        }

        return $values;
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
