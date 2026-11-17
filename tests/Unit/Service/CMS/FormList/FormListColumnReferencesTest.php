<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\FormList;

use App\Service\CMS\FormList\FormListColumnReferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The five list fields that name columns are rewritten in exactly the places
 * where a column is named, and nowhere else: values, labels and row fields stay.
 */
#[Group('form-list')]
final class FormListColumnReferencesTest extends TestCase
{
    private const TO_NAMES = ['section_11' => 'title', 'section_12' => 'seats', 'section_13' => 'status'];

    private static function map(string $name): string
    {
        return self::TO_NAMES[$name] ?? $name;
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function fields(): iterable
    {
        yield 'columns' => [
            FormListColumnReferences::FIELD_COLUMNS,
            '[{"field":"section_11","label":"Title","sortable":true},{"field":"entry_date","format":"date"},{"field":"section_12","format":"number"}]',
            '[{"field":"title","label":"Title","sortable":true},{"field":"entry_date","format":"date"},{"field":"seats","format":"number"}]',
        ];
        yield 'default order' => [
            FormListColumnReferences::FIELD_SORT,
            '[{"field":"section_12","direction":"desc"},{"field":"record_id","direction":"asc"}]',
            '[{"field":"seats","direction":"desc"},{"field":"record_id","direction":"asc"}]',
        ];
        yield 'controls keep their choices and labels' => [
            FormListColumnReferences::FIELD_CONTROLS,
            '[{"id":"state","type":"select","field":"section_13","label":"section_13","options":[{"value":"section_13","label":"Open"}]}]',
            '[{"id":"state","type":"select","field":"status","label":"section_13","options":[{"value":"section_13","label":"Open"}]}]',
        ];
        yield 'a fixed filter reaches into nested groups but never touches a value' => [
            FormListColumnReferences::FIELD_FIXED_FILTER,
            '{"mode":"and","children":[{"column":"section_13","operator":"neq","value":"section_11"},{"mode":"or","children":[{"column":"section_12","operator":"gt","value":"5"},{"column":"entry_date","operator":"isnotnull"}]}]}',
            '{"mode":"and","children":[{"column":"status","operator":"neq","value":"section_11"},{"mode":"or","children":[{"column":"seats","operator":"gt","value":"5"},{"column":"entry_date","operator":"isnotnull"}]}]}',
        ];
        yield 'quick actions: the assigned columns and the condition' => [
            FormListColumnReferences::FIELD_QUICK_ACTIONS,
            '[{"id":"done","label":"Done","confirm":true,"set":{"section_13":"done","section_12":"0"},"when":{"mode":"and","children":[{"column":"section_13","operator":"eq","value":"open"}]}}]',
            '[{"id":"done","label":"Done","confirm":true,"set":{"status":"done","seats":"0"},"when":{"mode":"and","children":[{"column":"status","operator":"eq","value":"open"}]}}]',
        ];
        yield 'a quick action without a condition' => [
            FormListColumnReferences::FIELD_QUICK_ACTIONS,
            '[{"id":"x","label":"X","set":{"section_13":"a"}}]',
            '[{"id":"x","label":"X","set":{"status":"a"}}]',
        ];
    }

    #[DataProvider('fields')]
    public function testEveryPlaceThatNamesAColumnIsRewritten(string $field, string $stored, string $expected): void
    {
        self::assertSame($expected, FormListColumnReferences::rewrite($field, $stored, self::map(...)));
    }

    #[DataProvider('fields')]
    public function testRewritingBackRestoresTheOriginal(string $field, string $stored, string $expected): void
    {
        $toKeys = static fn (string $name): string => array_flip(self::TO_NAMES)[$name] ?? $name;

        self::assertSame($stored, FormListColumnReferences::rewrite($field, $expected, $toKeys));
    }

    #[DataProvider('fields')]
    public function testNothingChangesWhenNoColumnIsMapped(string $field, string $stored, string $expected): void
    {
        self::assertNotSame($stored, $expected, 'The fixture names a column that the real mapping changes.');
        self::assertNull(FormListColumnReferences::rewrite($field, $stored, static fn (string $name): string => $name));
    }

    public function testAFieldThatIsNotAListFieldIsNeverRewritten(): void
    {
        self::assertNull(FormListColumnReferences::rewrite('page_size', '[{"field":"section_11"}]', self::map(...)));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unreadable(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'not json' => ['[{'];
        yield 'a scalar' => ['"section_11"'];
        yield 'a number' => ['7'];
    }

    #[DataProvider('unreadable')]
    public function testTextThatIsNotJsonOfTheExpectedShapeIsLeftAlone(string $stored): void
    {
        foreach (FormListColumnReferences::FIELDS as $field) {
            self::assertNull(FormListColumnReferences::rewrite($field, $stored, self::map(...)), $field);
        }
    }

    public function testEntriesOfTheWrongShapeAreSkippedNotRepaired(): void
    {
        $stored = '["section_11",{"field":7},{"field":"section_12"},{"label":"section_13"}]';

        self::assertSame(
            '["section_11",{"field":7},{"field":"seats"},{"label":"section_13"}]',
            FormListColumnReferences::rewrite(FormListColumnReferences::FIELD_COLUMNS, $stored, self::map(...)),
        );
    }

    public function testUnicodeAndSlashesStayReadable(): void
    {
        $stored = '[{"field":"section_11","label":{"en":"Titel / Größe","de":"Größe"}}]';

        self::assertSame(
            '[{"field":"title","label":{"en":"Titel / Größe","de":"Größe"}}]',
            FormListColumnReferences::rewrite(FormListColumnReferences::FIELD_COLUMNS, $stored, self::map(...)),
        );
    }
}
