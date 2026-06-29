<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\Entity\Field;
use App\Entity\Language;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Entity\Style;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Regression for issue #56: renaming a core form input must keep the SAME
 * `data_cols` column (only its `display_name` changes), never fork a second
 * column that splits historical from new submissions.
 *
 * The fix ties `data_cols.field_key` to the input's immutable **section id**
 * (`section_<id>`), not the submitted human name. `DataService::saveData()`
 * remaps the submitted name to that stable key via {@see FormFieldKeyResolver},
 * so a later rename only updates the auto `display_name`. This exercises the
 * real chain (resolver → `saveData` → `DataColumnService`) end to end inside the
 * DAMA transaction and asserts the public side effect on `data_cols`.
 */
final class FormFieldKeyRenameTest extends QaKernelTestCase
{
    private DataService $dataService;
    private FormFieldKeyResolver $resolver;
    private CacheService $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataService = $this->service(DataService::class);
        $this->resolver = $this->service(FormFieldKeyResolver::class);
        $this->cache = $this->service(CacheService::class);

        // DAMA reuses auto-increment ids across runs while Redis persists; drop
        // any stale data-table cache keyed by a reused id so reads are clean.
        $this->cache->withCategory(CacheService::CATEGORY_DATA_TABLES)->invalidateCategory();
    }

    public function testRenamingFormInputKeepsTheSameDataColumn(): void
    {
        // A form section with one named input section as its hierarchy child —
        // the same graph the renderer emits a `<input name="...">` for.
        $formSection = $this->createSection('qa_ffk_form', 'form-record');
        $inputSection = $this->createSection('qa_ffk_input', 'input');
        $this->linkHierarchy($formSection, $inputSection);
        $nameTranslation = $this->setInputName($inputSection, 'name2');

        $formTable = (string) $formSection->getId();
        $stableKey = FormFieldKeyResolver::FIELD_KEY_PREFIX . (int) $inputSection->getId();

        // The resolver maps the human name to the immutable section-id key.
        $this->freshenResolver();
        self::assertSame(
            ['name2' => $stableKey],
            $this->resolver->getNameToFieldKey($formTable),
            'The input name must resolve to its section-id field_key.',
        );

        // First submission (keyed by the human name, as the frontend/mobile send it).
        $this->submit($formTable, ['name2' => 'first value']);

        $columns = $this->columnsFor($formTable);
        self::assertCount(1, $columns, 'The first submission must create exactly one column.');
        self::assertSame($stableKey, $columns[0]['field_key'], 'field_key must be the section-id key, not the human name.');
        self::assertSame('name2', $columns[0]['display_name'], 'display_name auto-derives from the input name.');
        self::assertSame('auto', $columns[0]['display_name_source']);

        // Rename the input: only the `name` field content changes; the section
        // id (and therefore the stable key) does not. In production this is a
        // separate request, so the resolver memo is fresh — simulate that.
        $nameTranslation->setContent('changed');
        $this->em->flush();
        $this->freshenResolver();
        $this->cache->withCategory(CacheService::CATEGORY_DATA_TABLES)->invalidateCategory();

        self::assertSame(
            ['changed' => $stableKey],
            $this->resolver->getNameToFieldKey($formTable),
            'After the rename the new name must resolve to the SAME section-id key.',
        );

        // Second submission under the new name.
        $this->submit($formTable, ['changed' => 'second value']);

        $columns = $this->columnsFor($formTable);
        self::assertCount(
            1,
            $columns,
            'Renaming the input must NOT fork a second column (issue #56).',
        );
        self::assertSame($stableKey, $columns[0]['field_key'], 'The column keeps its immutable section-id key.');
        self::assertSame('changed', $columns[0]['display_name'], 'Only the display_name follows the rename.');
    }

    public function testSurveyJsStyleNonNumericTableKeepsSubmittedKey(): void
    {
        // A SurveyJS-style table name (non-numeric) is not a core form section,
        // so the resolver returns an empty map and the submitted key
        // (`question.name`) is stored verbatim — the per-source contract.
        $tableName = 'sh2_surveyjs_qa_ffk';

        self::assertSame([], $this->resolver->getNameToFieldKey($tableName));

        $this->submit($tableName, ['household.member_name' => 'Ada'], ['household.member_name' => 'Member name']);

        $columns = $this->columnsFor($tableName);
        self::assertCount(1, $columns);
        self::assertSame('household.member_name', $columns[0]['field_key'], 'Non-core sources keep their own submitted key.');
        self::assertSame('Member name', $columns[0]['display_name'], 'The supplied label becomes the auto display_name.');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param array<string, string> $formData
     * @param array<string, string> $labels
     */
    private function submit(string $tableName, array $formData, array $labels = []): void
    {
        $payload = $formData + [
            'id_users' => null,
            'trigger_type' => LookupService::ACTION_TRIGGER_TYPES_FINISHED,
        ];

        $recordId = $this->dataService->saveData(
            $tableName,
            $payload,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
            null,
            false,
            $labels === [] ? null : $labels,
        );

        self::assertIsInt($recordId, 'saveData must return a record id.');
    }

    private function createSection(string $name, string $styleName): Section
    {
        $section = new Section();
        $section->setName($name);
        $section->setStyle($this->style($styleName));
        $this->em->persist($section);
        $this->em->flush();

        return $section;
    }

    private function linkHierarchy(Section $parent, Section $child): void
    {
        $link = new SectionsHierarchy();
        $link->setParentSection($parent);
        $link->setChildSection($child);
        $link->setPosition(10);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function setInputName(Section $input, string $name): SectionsFieldsTranslation
    {
        $translation = new SectionsFieldsTranslation();
        $translation->setSection($input);
        $translation->setField($this->nameField());
        $translation->setLanguage($this->language());
        $translation->setContent($name);
        $this->em->persist($translation);
        $this->em->flush();

        return $translation;
    }

    /**
     * Clear the resolver's per-request memo so a post-rename read sees the new
     * name. In production each request has a fresh resolver, so this only undoes
     * a same-process test artifact, not a production behaviour.
     */
    private function freshenResolver(): void
    {
        $property = new \ReflectionProperty(FormFieldKeyResolver::class, 'cache');
        $property->setValue($this->resolver, []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columnsFor(string $tableName): array
    {
        // Resolve the provenance through the lookups FK (NULL id_display_name_source
        // is the default `auto`), so the assertion exercises the new FK model.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT dc.field_key, dc.display_name,'
            . " COALESCE(l.lookup_code, 'auto') AS display_name_source"
            . ' FROM data_cols dc'
            . ' JOIN data_tables dt ON dt.id = dc.id_data_tables'
            . ' LEFT JOIN lookups l ON l.id = dc.id_display_name_source'
            . ' WHERE dt.name = :name'
            . ' ORDER BY dc.id',
            ['name' => $tableName],
        );

        return $rows;
    }

    private function nameField(): Field
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => 'name']);
        self::assertInstanceOf(Field::class, $field, 'The seeded "name" field must exist.');

        return $field;
    }

    private function language(): Language
    {
        $language = $this->em->getRepository(Language::class)->find(1);
        self::assertInstanceOf(Language::class, $language, 'Default language id 1 must exist.');

        return $language;
    }

    private function style(string $name): Style
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Style::class, $style, sprintf('Seeded style "%s" must exist.', $name));

        return $style;
    }
}
