<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\FormList;

use App\Controller\Api\V1\Frontend\FormListController;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\FormList\FormListConfig;
use App\Service\CMS\FormList\FormListConfigReader;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The seed migration and the code must agree on every default of the two list
 * styles: a field that was never saved reads the default from the code, and the
 * editor shows the default the migration wrote. They are written once in each
 * place; this test keeps them identical, keeps the editor's field list complete
 * and checks that the routes are registered the way the code serves them.
 */
#[Group('form-list')]
final class FormListSeedDefaultsTest extends QaKernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->em->getConnection();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function styles(): iterable
    {
        yield 'entry-form-list' => [StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'data-view' => [StyleNames::STYLE_DATA_VIEW];
    }

    #[DataProvider('styles')]
    public function testEveryDefaultOfTheCodeIsWhatTheMigrationSeeded(string $style): void
    {
        $seeded = $this->seededDefaults($style);

        foreach (FormListConfig::defaultsFor($style) as $field => $default) {
            self::assertArrayHasKey($field, $seeded, sprintf('"%s" is not linked to the style "%s".', $field, $style));
            self::assertSame($default, $seeded[$field], sprintf('The default of "%s" differs between the code and the migration.', $field));
        }
    }

    #[DataProvider('styles')]
    public function testTheStyleOffersNoFieldTheCodeIgnores(string $style): void
    {
        // Form authoring fields on entry-form-list are read by the ordinary form
        // path (EffectiveFormFactory / FormStyle), not by FormListConfig.
        $formFields = $style === StyleNames::STYLE_ENTRY_FORM_LIST ? [
            'name', 'btn_save_label', 'btn_save_color', 'btn_update_label', 'btn_update_color',
            'btn_cancel_label', 'btn_cancel_color', 'alert_success', 'alert_success_title',
            'alert_error', 'alert_error_title', 'confirm_submit', 'confirm_message',
            'buttons_size', 'buttons_radius', 'buttons_variant', 'buttons_position',
            'buttons_order', 'redirect_at_end',
        ] : [];
        $known = array_merge(array_keys(FormListConfig::defaultsFor($style)), ['title', 'spacing', 'data_table'], $formFields);

        foreach (array_keys($this->seededDefaults($style)) as $field) {
            self::assertContains($field, $known, sprintf('The style "%s" offers the field "%s" but nothing reads it.', $style, $field));
        }
    }

    #[DataProvider('styles')]
    public function testASectionThatSavedNothingIsReadFromTheSeededDefaultsAndPassesTheReader(string $style): void
    {
        $reader = $this->service(FormListConfigReader::class);
        $raw = [];
        foreach ($this->seededDefaults($style) as $field => $default) {
            $raw[$field] = [1 => $default];
        }
        // A data view needs a table; a shared one needs columns. The author sets those; the rest is the seeded default.
        if ($style === StyleNames::STYLE_DATA_VIEW) {
            $raw['data_table'] = [1 => '1'];
            $raw['list_columns'] = [1 => '[{"field":"title"}]'];
        }

        $config = $reader->parse(1, $style, $raw, 1);

        self::assertSame(25, $config->pageSize);
        self::assertSame('list', $config->key);
        self::assertSame($style === StyleNames::STYLE_ENTRY_FORM_LIST, $config->ownEntriesOnly);
    }

    public function testTheStylesLiveInTheGroupsAndRenderTargetsOfTheStylesTheyReplaceOnPages(): void
    {
        foreach ([StyleNames::STYLE_ENTRY_FORM_LIST => 'entry-record-form', StyleNames::STYLE_DATA_VIEW => 'entry-table'] as $style => $like) {
            $row = $this->db->fetchAssociative(
                'SELECT s.id_style_groups AS grp, s.id_render_target AS target, s.can_have_children AS children, l.id_style_groups AS like_grp, l.id_render_target AS like_target'
                . ' FROM styles s JOIN styles l ON l.name = :like WHERE s.name = :style',
                ['style' => $style, 'like' => $like],
            );
            self::assertIsArray($row, sprintf('The style "%s" is seeded.', $style));
            self::assertSame($row['like_grp'], $row['grp']);
            self::assertSame($row['like_target'], $row['target'], 'The style is drawn by the same targets as the style it replaces.');
            self::assertSame(1, self::coerceInt($row['children']), 'The list holds its form; the data view holds the layout of one record.');
        }
    }

    public function testEveryRouteOfTheListsIsRegisteredWithItsPathAndMethod(): void
    {
        $expected = [
            'form_list_state' => ['/lists/{section_id}/state', 'GET'],
            'form_list_quick_action' => ['/lists/{section_id}/records/{record_id}/actions/{action_id}', 'POST'],
            'form_list_history' => ['/lists/{section_id}/records/{record_id}/history', 'GET'],
            'form_list_version' => ['/lists/{section_id}/records/{record_id}/versions/{version}', 'GET'],
            'form_list_compare' => ['/lists/{section_id}/records/{record_id}/compare', 'GET'],
            'form_list_restore' => ['/lists/{section_id}/records/{record_id}/restore', 'POST'],
            'form_list_current_file' => ['/lists/{section_id}/records/{record_id}/files/{field_key}', 'GET'],
            'form_list_version_file' => ['/lists/{section_id}/records/{record_id}/versions/{version}/files/{field_key}', 'GET'],
        ];

        $rows = $this->db->fetchAllAssociative("SELECT route_name, path, methods, controller FROM api_routes WHERE route_name LIKE 'form_list_%' AND version = 'v1'");
        $actual = [];
        foreach ($rows as $row) {
            $controller = self::coerceString($row['controller']);
            $actual[self::coerceString($row['route_name'])] = [self::coerceString($row['path']), self::coerceString($row['methods'])];
            self::assertStringStartsWith('App\\Controller\\Api\\V1\\Frontend\\FormListController::', $controller);
            self::assertTrue(
                method_exists(FormListController::class, substr($controller, strlen('App\\Controller\\Api\\V1\\Frontend\\FormListController::'))),
                'The controller method of the route exists.',
            );
        }
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    /**
     * @return array<string, string> field name => seeded default
     */
    private function seededDefaults(string $style): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT f.name AS name, rfs.default_value AS default_value'
            . ' FROM rel_fields_styles rfs JOIN fields f ON f.id = rfs.id_fields JOIN styles s ON s.id = rfs.id_styles'
            . ' WHERE s.name = :style',
            ['style' => $style],
        );
        $defaults = [];
        foreach ($rows as $row) {
            $defaults[self::coerceString($row['name'])] = $row['default_value'] === null ? '' : self::coerceString($row['default_value']);
        }

        return $defaults;
    }
}
