<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\DataTable;
use App\Entity\Section;
use App\Service\CMS\DataColumnService;
use App\Service\CMS\DataQuery\DataColValueType;
use App\Service\CMS\DataService;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\Core\LookupService;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * A data column declares how its stored text is read (`text`, `number`, `date`,
 * `datetime`, `boolean`). The declaration follows the form input that feeds the
 * column: it is made when the column is created, corrected on the next save, and
 * moved when the author changes the input. Lists sort numbers as numbers and
 * dates as dates only because of this declaration.
 */
#[PhpUnitGroup('form-list')]
final class DataColumnValueTypeTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: ?string, 3: string}>
     */
    public static function inputStyles(): iterable
    {
        yield 'number input' => ['number-input', null, null, 'number'];
        yield 'slider' => ['slider', null, null, 'number'];
        yield 'rating' => ['rating', null, null, 'number'];
        yield 'checkbox' => ['checkbox', null, null, 'boolean'];
        yield 'switch' => ['switch', null, null, 'boolean'];
        yield 'plain input' => ['input', 'text', null, 'text'];
        yield 'input of type number' => ['input', 'number', null, 'number'];
        yield 'input of type date, in any case' => ['input', ' Date ', null, 'date'];
        yield 'input of type checkbox' => ['input', 'checkbox', null, 'boolean'];
        yield 'input without a type' => ['input', null, null, 'text'];
        yield 'datepicker without a type is a date' => ['datepicker', null, null, 'date'];
        yield 'datepicker of type date' => ['datepicker', null, 'date', 'date'];
        yield 'datepicker of type datetime' => ['datepicker', null, 'datetime', 'datetime'];
        yield 'datepicker of type time stays text' => ['datepicker', null, 'time', 'text'];
        yield 'text input' => ['text-input', null, null, 'text'];
        yield 'textarea' => ['textarea', null, null, 'text'];
        yield 'select' => ['select', null, null, 'text'];
        yield 'no style' => [null, null, null, 'text'];
    }

    /**
     * Texts and what they are, written down independently of the code: the UTC instant of a datetime text, the day of a
     * date text, null when the text is not one. One table answers the PHP rule, the SQL rule and their parity.
     *
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string}> text, instant (UTC, `Y-m-d H:i:s`), day
     */
    public static function temporalTexts(): iterable
    {
        // -- real dates and times
        yield 'a day' => ['2026-11-14', '2026-11-14 00:00:00', '2026-11-14'];
        yield 'a day and a time' => ['2026-11-14 10:00', '2026-11-14 10:00:00', '2026-11-14'];
        yield 'with a T and seconds' => ['2026-11-14T10:00:30', '2026-11-14 10:00:30', '2026-11-14'];
        yield 'a fraction is not compared' => ['2026-11-14 10:00:30.987', '2026-11-14 10:00:30', '2026-11-14'];
        yield 'Z' => ['2026-11-14T10:00:00Z', '2026-11-14 10:00:00', '2026-11-14'];
        yield 'Z with a fraction' => ['2026-11-14T10:00:00.000Z', '2026-11-14 10:00:00', '2026-11-14'];
        yield 'an offset east of UTC' => ['2026-11-14T10:00:00+01:00', '2026-11-14 09:00:00', '2026-11-14'];
        yield 'an offset without a colon' => ['2026-11-14T10:00:00+0100', '2026-11-14 09:00:00', '2026-11-14'];
        yield 'an offset west of UTC' => ['2026-11-14T10:00:00-05:30', '2026-11-14 15:30:00', '2026-11-14'];
        yield 'an offset on a time without seconds' => ['2026-11-14T10:00+05:45', '2026-11-14 04:15:00', '2026-11-14'];
        yield 'an offset that crosses midnight forward' => ['2026-11-14T23:30:00-02:00', '2026-11-15 01:30:00', '2026-11-14'];
        yield 'an offset that crosses midnight backward' => ['2026-11-14T00:30:00+02:00', '2026-11-13 22:30:00', '2026-11-14'];
        yield 'the largest offset east' => ['2026-11-14T10:00:00+14:00', '2026-11-13 20:00:00', '2026-11-14'];
        yield 'the largest offset west' => ['2026-11-14T10:00:00-13:59', '2026-11-14 23:59:00', '2026-11-14'];
        yield 'a zero offset' => ['2026-11-14T10:00:00+00:00', '2026-11-14 10:00:00', '2026-11-14'];
        yield 'a leap day' => ['2024-02-29 12:00', '2024-02-29 12:00:00', '2024-02-29'];
        yield 'a leap day of a century divisible by 400' => ['2000-02-29', '2000-02-29 00:00:00', '2000-02-29'];
        yield 'a leap day at the offset that moves it' => ['2024-02-29T00:30:00+01:00', '2024-02-28 23:30:00', '2024-02-29'];
        yield 'the last second of a year' => ['2026-12-31T23:59:59', '2026-12-31 23:59:59', '2026-12-31'];
        yield 'the new year through an offset' => ['2026-01-01T00:30:00+01:00', '2025-12-31 23:30:00', '2026-01-01'];
        yield 'spaces around the text' => ['  2026-11-14 10:00  ', '2026-11-14 10:00:00', '2026-11-14'];
        yield 'the lowest year' => ['1000-01-01', '1000-01-01 00:00:00', '1000-01-01'];
        yield 'the highest year' => ['9999-12-31 23:59:59', '9999-12-31 23:59:59', '9999-12-31'];
        yield 'the first instant before the lowest year (through an offset)' => ['1000-01-01T00:00:00+01:00', '0999-12-31 23:00:00', '1000-01-01'];

        // -- days that are not on the calendar
        yield 'February 29 of a common year' => ['2026-02-29 10:00', null, null];
        yield 'February 29 of a century that is not a leap year' => ['2100-02-29', null, null];
        yield 'February 30' => ['2026-02-30', null, null];
        yield 'April 31' => ['2026-04-31', null, null];
        yield 'month 13' => ['2026-13-01', null, null];
        yield 'month 0' => ['2026-00-10', null, null];
        yield 'day 0' => ['2026-11-00', null, null];
        yield 'day 32' => ['2026-11-32', null, null];
        yield 'year 0' => ['0000-01-01', null, null];
        yield 'a year below 1000' => ['0999-12-31', null, null];

        // -- clocks and offsets that do not exist
        yield 'a clock of 99:99:99' => ['2026-11-14 99:99:99', null, null];
        yield 'hour 24' => ['2026-11-14 24:00', null, null];
        yield 'minute 60' => ['2026-11-14 23:60', null, null];
        yield 'second 60' => ['2026-11-14 23:59:60', null, null];
        yield 'an hour with one digit' => ['2026-11-14 7:30', null, null];
        yield 'an offset of +99:99' => ['2026-11-14T10:00:00+99:99', null, null];
        yield 'an offset past +14:00' => ['2026-11-14T10:00:00+14:01', null, null];
        yield 'an offset of +15:00' => ['2026-11-14T10:00:00+15:00', null, null];
        yield 'an offset of -14:00' => ['2026-11-14T10:00:00-14:00', null, null];
        yield 'an offset with minutes past 59' => ['2026-11-14T10:00:00+01:60', null, null];
        yield 'an offset of +24:00' => ['2026-11-14T10:00:00+24:00', null, null];
        yield 'an offset with one digit' => ['2026-11-14T10:00:00+1:00', null, null];
        yield 'a shift past the last day' => ['9999-12-31T23:59:59-01:00', null, '9999-12-31'];

        // -- shapes that are not a date or a time
        yield 'a lower case t' => ['2026-11-14t10:00', null, null];
        yield 'a lower case z' => ['2026-11-14T10:00:00z', null, null];
        yield 'a space before the offset' => ['2026-11-14T10:00:00 +01:00', null, null];
        yield 'an hour only' => ['2026-11-14T10', null, null];
        yield 'a dangling colon' => ['2026-11-14T10:00:', null, null];
        yield 'a dangling point' => ['2026-11-14T10:00:00.', null, null];
        yield 'a fraction of minutes' => ['2026-11-14T10:00.5', null, null];
        yield 'two zones' => ['2026-11-14T10:00:00Z+01:00', null, null];
        yield 'a zone on a day' => ['2026-11-14Z', null, null];
        yield 'text after the time' => ['2026-11-14 10:00:00 junk', null, null];
        yield 'text after the day' => ['2026-11-14junk', null, null];
        yield 'words' => ['tomorrow', null, null];
        yield 'slashes' => ['2026/11/14', null, null];
        yield 'no separators' => ['20261114', null, null];
        yield 'a new line inside' => ["2026-11-14\n10:00", null, null];
        yield 'a new line at the end' => ["2026-11-14\n", null, null];
    }

    #[DataProvider('temporalTexts')]
    public function testDatesAndTimesAreReadTheSameWayInPhpAndInSql(string $text, ?string $instant, ?string $day): void
    {
        // A datetime: the PHP rule, the SQL rule and the written-down answer agree.
        self::assertSame($instant, DataColValueType::instantOf($text), 'PHP reads the instant of ' . json_encode($text));
        self::assertSame($instant !== null, DataColValueType::reads('datetime', $text));
        $sqlInstant = $this->db->fetchOne('SELECT ' . DataColValueType::sql($this->db->quote($text), 'datetime'));
        self::assertSame($instant, $sqlInstant === false || $sqlInstant === null ? null : self::coerceString($sqlInstant), 'SQL reads the instant of ' . json_encode($text));

        // A date: the day as written; the time after it (when there is one) has to exist too.
        self::assertSame($day, DataColValueType::dayOf($text), 'PHP reads the day of ' . json_encode($text));
        self::assertSame($day !== null, DataColValueType::reads('date', $text));
        $sqlDay = $this->db->fetchOne('SELECT ' . DataColValueType::sql($this->db->quote($text), 'date'));
        self::assertSame($day, $sqlDay === false || $sqlDay === null ? null : self::coerceString($sqlDay), 'SQL reads the day of ' . json_encode($text));
    }

    public function testAnEmptyValueIsAlwaysAcceptedAndReadAsMissing(): void
    {
        foreach (['date', 'datetime', 'number', 'boolean', 'text'] as $type) {
            self::assertTrue(DataColValueType::reads($type, ''));
            self::assertTrue(DataColValueType::reads($type, '   '));
        }
        foreach (['date', 'datetime', 'number'] as $type) {
            self::assertNull($this->db->fetchOne('SELECT ' . DataColValueType::sql($this->db->quote(''), $type)), 'An empty text is missing for a typed comparison: ' . $type);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function numbersAndFlags(): iterable
    {
        yield 'an integer' => ['number', '42', true];
        yield 'a signed decimal' => ['number', '-3.50', true];
        yield 'a plus sign' => ['number', '+7', true];
        yield 'an exponent' => ['number', '1e3', false];
        yield 'a comma decimal' => ['number', '3,5', false];
        yield 'letters' => ['number', 'abc', false];
        yield 'a number with a new line' => ['number', "12\n", false];
        yield 'yes' => ['boolean', 'yes', true];
        yield 'off' => ['boolean', 'off', true];
        yield 'perhaps' => ['boolean', 'perhaps', false];
        yield 'a capital TRUE' => ['boolean', 'TRUE', true];
    }

    #[DataProvider('numbersAndFlags')]
    public function testNumbersAndFlagsAreReadTheSameWayInPhpAndInSql(string $type, string $text, bool $reads): void
    {
        self::assertSame($reads, DataColValueType::reads($type, $text));
        $sql = $this->db->fetchOne('SELECT ' . DataColValueType::sql($this->db->quote($text), $type));
        self::assertSame($reads, $sql !== false && $sql !== null, 'SQL and PHP agree for ' . json_encode($text));
    }

    #[DataProvider('inputStyles')]
    public function testTheTypeFollowsTheStyleOfTheInput(?string $style, ?string $typeInput, ?string $picker, string $expected): void
    {
        self::assertSame($expected, FormFieldKeyResolver::valueTypeFor($style, $typeInput, $picker));
    }

    public function testAColumnIsCreatedWithTheTypeOfItsInput(): void
    {
        [, $form] = $this->typedForm('qa_vt_create');

        $types = $this->columnTypes($form);

        self::assertSame(
            ['agree' => 'boolean', 'at' => 'datetime', 'born' => 'date', 'note' => null, 'qty' => 'number'],
            $types,
            'Every column carries the type of its input; text is stored as no type.',
        );
    }

    public function testChangingTheTypeOfAnInputMovesItsColumn(): void
    {
        [, $form, $inputs] = $this->typedForm('qa_vt_change');
        $born = $inputs['born'];
        $columns = $this->service(DataColumnService::class);
        self::assertSame('date', $this->columnTypes($form)['born']);

        $this->pages->setSectionField($born, 'type_input', 'number');
        $changed = $columns->syncValueTypeForInputSection($born);
        self::assertSame([$this->tableId($form)], $changed, 'The tables whose column changed are reported, so their caches can be dropped.');
        self::assertSame('number', $this->columnTypes($form)['born']);

        $this->pages->setSectionField($born, 'type_input', 'text');
        self::assertSame([$this->tableId($form)], $columns->syncValueTypeForInputSection($born));
        self::assertNull($this->columnTypes($form)['born'], 'Text is stored as no type.');

        self::assertSame([], $columns->syncValueTypeForInputSection($born), 'An input whose column already has its type changes nothing.');
    }

    public function testAnInputWithoutAColumnYetChangesNothing(): void
    {
        $page = $this->pages->createPage('qa_vt_nocol', false);
        $form = $this->pages->createSection('qa_vt_nocol_form', 'form-record');
        $this->pages->linkSectionToPage($page, $form, 10);
        $input = $this->formInput($form, 'qa_vt_nocol_in', 'input', 'amount');
        $this->pages->setSectionField($input, 'type_input', 'number');

        self::assertSame([], $this->service(DataColumnService::class)->syncValueTypeForInputSection($input));
    }

    public function testASubmissionCorrectsAColumnWhoseTypeIsOutOfStep(): void
    {
        [, $form, $inputs] = $this->typedForm('qa_vt_refresh');
        $qty = 'section_' . $inputs['qty']->getId();
        $this->db->executeStatement(
            'UPDATE data_cols SET id_value_type = NULL WHERE id_data_tables = :t AND field_key = :k',
            ['t' => $this->tableId($form), 'k' => $qty],
        );
        $this->em->clear(); // the change was made behind the ORM's back, as another request would make it
        self::assertNull($this->columnTypes($form)['qty']);

        $this->service(DataService::class)->saveData(
            (string) $form->getId(),
            ['qty' => '3', 'note' => 'again'],
            LookupService::TRANSACTION_BY_BY_USER,
            null,
            false,
        );

        self::assertSame('number', $this->columnTypes($form)['qty'], 'The next save restores the declared type.');
    }

    public function testAColumnOfAnotherDataSourceKeepsItsType(): void
    {
        // SurveyJS and validation tables key their columns by question name, not by input section.
        $tableId = $this->grid->table('qa_vt_other', ['question1' => 'number']);
        $table = $this->em->getRepository(DataTable::class)->find($tableId);
        self::assertInstanceOf(DataTable::class, $table);

        $this->service(DataColumnService::class)->resolveColumns($table, ['question1']);
        $this->em->clear();

        self::assertSame(
            'number',
            $this->db->fetchOne(
                'SELECT lv.lookup_code FROM data_cols c JOIN lookups lv ON lv.id = c.id_value_type WHERE c.id_data_tables = :t AND c.field_key = :k',
                ['t' => $tableId, 'k' => 'question1'],
            ),
            'Only the columns of core form inputs are declared by their input.',
        );
    }

    // -- fixtures ---------------------------------------------------------------

    /**
     * A form with one input of each kind and one saved record that creates the columns.
     *
     * @return array{0: \App\Entity\Page, 1: Section, 2: array<string, Section>}
     */
    private function typedForm(string $prefix): array
    {
        $page = $this->pages->createPage($prefix, false);
        $form = $this->pages->createSection($prefix . '_form', 'form-record');
        $this->pages->linkSectionToPage($page, $form, 10);

        $inputs = [
            'qty' => $this->formInput($form, $prefix . '_qty', 'number-input', 'qty', 10),
            'born' => $this->formInput($form, $prefix . '_born', 'input', 'born', 20),
            'at' => $this->formInput($form, $prefix . '_at', 'datepicker', 'at', 30),
            'agree' => $this->formInput($form, $prefix . '_agree', 'checkbox', 'agree', 40),
            'note' => $this->formInput($form, $prefix . '_note', 'text-input', 'note', 50),
        ];
        $this->pages->setSectionField($inputs['born'], 'type_input', 'date');
        $this->pages->setSectionField($inputs['at'], 'web_datepicker_type', 'datetime');

        $this->service(DataService::class)->saveData(
            (string) $form->getId(),
            ['qty' => '7', 'born' => '2000-01-31', 'at' => '2026-01-01 10:00:00', 'agree' => '1', 'note' => 'hello'],
            LookupService::TRANSACTION_BY_BY_USER,
            null,
            false,
        );

        return [$page, $form, $inputs];
    }

    /**
     * Input name => declared type code (null for text) of the columns of a form's table.
     *
     * @return array<string, string|null>
     */
    private function columnTypes(Section $form): array
    {
        $names = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT h.id_child_section AS id, t.content AS name FROM rel_sections_hierarchy h'
            . ' JOIN sections_fields_translation t ON t.id_sections = h.id_child_section'
            . ' JOIN fields f ON f.id = t.id_fields AND f.name = :field WHERE h.id_parent_section = :form',
            ['field' => 'name', 'form' => $form->getId()],
        ) as $row) {
            $names['section_' . self::coerceInt($row['id'])] = self::coerceString($row['name']);
        }

        $types = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT c.field_key AS field_key, lv.lookup_code AS type FROM data_cols c'
            . ' LEFT JOIN lookups lv ON lv.id = c.id_value_type WHERE c.id_data_tables = :t',
            ['t' => $this->tableId($form)],
        ) as $row) {
            $key = self::coerceString($row['field_key']);
            $types[$names[$key] ?? $key] = $row['type'] === null ? null : self::coerceString($row['type']);
        }
        ksort($types);

        return $types;
    }

    private function tableId(Section $form): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT id FROM data_tables WHERE name = :n', ['n' => (string) $form->getId()]));
    }
}
