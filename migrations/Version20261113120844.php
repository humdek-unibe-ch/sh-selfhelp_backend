<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `entry-form-list` and `data-view` styles and the routes of their server-rendered state.
 *
 *  - `entry-form-list`: a list of the records of a form (search, sort, filters, paging) from
 *    which a visitor adds, views, edits and deletes records in a modal or on a page. It holds
 *    the form that creates and edits the records.
 *  - `data-view`: the same list, read only, for public or signed-in visitors.
 *
 * The defaults written here are the defaults `App\Service\CMS\FormList\FormListConfig` applies
 * to a field that was never saved; `FormListSeedDefaultsTest` keeps the two identical.
 * Nothing is converted: the styles are new and no existing section changes.
 */
final class Version20261113120844 extends AbstractMigration
{
    private const API_VERSION = 'v1';
    private const CONTROLLER = 'App\\Controller\\Api\\V1\\Frontend\\FormListController::';

    /** Routes: name => [path, method, controller method, requirements]. */
    private const ROUTES = [
        'form_list_state' => ['/lists/{section_id}/state', 'GET', 'state', ['section_id' => '\\d+']],
        'form_list_quick_action' => ['/lists/{section_id}/records/{record_id}/actions/{action_id}', 'POST', 'quickAction', ['section_id' => '\\d+', 'record_id' => '\\d+', 'action_id' => '[a-z][a-z0-9_]{0,31}']],
        'form_list_history' => ['/lists/{section_id}/records/{record_id}/history', 'GET', 'history', ['section_id' => '\\d+', 'record_id' => '\\d+']],
        'form_list_version' => ['/lists/{section_id}/records/{record_id}/versions/{version}', 'GET', 'version', ['section_id' => '\\d+', 'record_id' => '\\d+', 'version' => '\\d+']],
        'form_list_compare' => ['/lists/{section_id}/records/{record_id}/compare', 'GET', 'compare', ['section_id' => '\\d+', 'record_id' => '\\d+']],
        'form_list_restore' => ['/lists/{section_id}/records/{record_id}/restore', 'POST', 'restore', ['section_id' => '\\d+', 'record_id' => '\\d+']],
        'form_list_version_file' => ['/lists/{section_id}/records/{record_id}/versions/{version}/files/{field_key}', 'GET', 'versionFile', ['section_id' => '\\d+', 'record_id' => '\\d+', 'version' => '\\d+', 'field_key' => '[A-Za-z0-9_]+']],
    ];

    public function getDescription(): string
    {
        return 'Register the entry-form-list and data-view styles, their fields and defaults, and the list state routes.';
    }

    public function up(Schema $schema): void
    {
        $this->addStyle(
            'entry-form-list',
            'entry-record-form',
            1,
            'A list of the records of a form with search, sort, filters and paging. A visitor adds, views, edits and deletes records in a modal or on a page; by default only their own. Put the form that creates and edits the records inside it.',
        );
        $this->addStyle(
            'data-view',
            'entry-table',
            0,
            'A read-only list of the records of a data table for public or signed-in visitors: table or cards, search, sort, filters and paging, each record optionally linking to a detail page. It never writes.',
        );

        // Fields: name => [type, display, config].
        $fields = [
            'list_key' => ['text', 0, null],
            'list_layout' => ['segment', 0, ['options' => [['text' => 'Table', 'value' => 'table'], ['text' => 'Cards', 'value' => 'cards']]]],
            'form_presentation' => ['segment', 0, ['options' => [['text' => 'Modal', 'value' => 'modal'], ['text' => 'Page', 'value' => 'page']]]],
            'page_size' => ['number', 0, null],
            'list_columns' => ['json', 0, ['rows' => 8, 'placeholder' => '[{"field":"section_1234","label":"Name","sortable":true,"searchable":true}]']],
            'list_sort' => ['json', 0, ['rows' => 3, 'placeholder' => '[{"field":"entry_date","direction":"desc"}]']],
            'list_controls' => ['json', 0, ['rows' => 8, 'placeholder' => '[{"id":"status","type":"select","field":"section_1235","label":"Status"}]']],
            'list_fixed_filter' => ['json', 0, ['rows' => 5, 'placeholder' => '{"mode":"and","children":[{"column":"section_1235","operator":"eq","value":"published"}]}']],
            'quick_actions' => ['json', 0, ['rows' => 8, 'placeholder' => '[{"id":"approve","label":"Approve","set":{"section_1235":"approved"}}]']],
            'allow_create' => ['checkbox', 0, null],
            'allow_update' => ['checkbox', 0, null],
            'allow_delete' => ['checkbox', 0, null],
            'show_history' => ['checkbox', 0, null],
            'detail_url' => ['text', 0, null],
            'label_add' => ['text', 1, null],
            'label_empty' => ['text', 1, null],
            'label_search' => ['text', 1, null],
            'label_confirm_delete' => ['text', 1, null],
        ];
        foreach ($fields as $name => [$type, $display, $config]) {
            $this->addField($name, $type, $display, $config);
        }

        // Field links: style => [field => [default, title, help]].
        $common = [
            'title' => ['', 'Title', 'Heading shown above the list.'],
            'data_table' => ['', 'Data table', 'The table the list reads. Leave empty to use the table of the form inside the list.'],
            'list_key' => ['list', 'List name', 'The list\'s name in the page address: lowercase letters, digits and underscore, starting with a letter (e.g. `tasks` gives `tasks.page`, `tasks.q`). Needed only when a page holds more than one list; every list on a page needs its own name.'],
            'list_layout' => ['table', 'Layout', 'Show the records as a table or as cards.'],
            'page_size' => ['25', 'Records per page', 'How many records one page shows (1-100).'],
            'list_columns' => ['[]', 'Columns', 'JSON array of the columns to show: {"field","label","sortable","searchable","format"}. Leave empty to show every column of an own-entries list; a shared or public list needs explicit columns. format: text, number, date, datetime, boolean, badge, file. Searchable columns are the ones the search box looks in.'],
            'list_sort' => ['[{"field":"entry_date","direction":"desc"}]', 'Default order', 'JSON array of up to 3 {"field","direction"} (asc or desc): the order before the visitor picks one. `entry_date` sorts by creation time.'],
            'list_controls' => ['[]', 'Filter controls', 'JSON array of the filter controls shown above the list: {"id","type","field","label"} with type text, select, multi_select, boolean, date_range or number_range. Choices come from the data unless given as "options".'],
            'list_fixed_filter' => ['{"mode":"and","children":[]}', 'Fixed filter', 'JSON filter that bounds the records the list can ever show, whatever the visitor types: {"mode":"and|or","children":[{"column","operator","value"}]}.'],
            'label_empty' => ['No entries yet.', 'Empty text', 'Shown when the list has no records.'],
            'label_search' => ['Search', 'Search label', 'Label of the search box.'],
            'spacing' => ['', 'Spacing', 'Outer spacing of the list.'],
        ];
        $listOnly = [
            'own_entries_only' => ['1', 'Own entries only', 'When enabled the list shows and changes only the signed-in visitor\'s own records (guests see nothing). Turn it off for a shared list; changing a record then needs the table\'s update permission.'],
            'allow_create' => ['1', 'Allow add', 'Show the button that adds a record.'],
            'allow_update' => ['1', 'Allow edit', 'Let visitors edit records they may change.'],
            'allow_delete' => ['1', 'Allow delete', 'Let visitors delete records they may delete.'],
            'form_presentation' => ['modal', 'Form presentation', 'Open the form in a modal over the list or on its own page (the list stays at the same address; the open record is part of it).'],
            'quick_actions' => ['[]', 'Quick actions', 'JSON array of one-click changes to a record: {"id","label","confirm","set":{"field":"value"},"when":{filter}}. The server applies `set` as a normal edit; `when` limits the records the action is offered for.'],
            'show_history' => ['0', 'Show history', 'Let visitors see the earlier versions of a record they may edit, compare them and restore one.'],
            'label_add' => ['Add entry', 'Add label', 'Label of the add button.'],
            'label_confirm_delete' => ['Delete this entry?', 'Delete confirmation', 'Question asked before a record is deleted.'],
        ];
        $viewOnly = [
            'own_entries_only' => ['0', 'Own entries only', 'When enabled the view shows only the signed-in visitor\'s own records. Leave it off for a public view; a public view needs explicit columns.'],
            'detail_url' => ['', 'Detail page', 'Site path a record links to, containing `{record_id}` once (e.g. `/events/{record_id}`). Leave empty for no link.'],
            'label_empty' => ['Nothing to show yet.', 'Empty text', 'Shown when the view has no records.'],
        ];

        foreach (['entry-form-list' => $listOnly, 'data-view' => $viewOnly] as $style => $own) {
            foreach (($own + $common) as $field => [$default, $title, $help]) {
                $this->linkField($style, $field, $default, $title, $help);
            }
        }

        foreach (self::ROUTES as $name => [$path, $method, $action, $requirements]) {
            $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [$name, self::API_VERSION]);
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
                [$name, self::API_VERSION, $path, self::CONTROLLER . $action, $method, json_encode($requirements, JSON_THROW_ON_ERROR)],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $inUse = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sections s INNER JOIN styles st ON st.id = s.id_styles WHERE st.name IN ('entry-form-list', 'data-view')",
        );
        $this->abortIf($inUse > 0, 'Delete the sections that use the entry-form-list or data-view style first.');

        foreach (array_keys(self::ROUTES) as $name) {
            $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [$name, self::API_VERSION]);
        }
        $this->addSql("DELETE rfs FROM rel_fields_styles rfs INNER JOIN styles st ON st.id = rfs.id_styles WHERE st.name IN ('entry-form-list', 'data-view')");
        $this->addSql("DELETE FROM styles WHERE name IN ('entry-form-list', 'data-view')");
        $this->addSql(
            'DELETE f FROM fields f WHERE f.name IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'AND NOT EXISTS (SELECT 1 FROM rel_fields_styles r WHERE r.id_fields = f.id)',
            [
                'list_key', 'list_layout', 'form_presentation', 'page_size', 'list_columns', 'list_sort',
                'list_controls', 'list_fixed_filter', 'quick_actions', 'allow_create', 'allow_update',
                'allow_delete', 'show_history', 'detail_url', 'label_add', 'label_empty', 'label_search',
                'label_confirm_delete',
            ],
        );
    }

    /** A style with the group and the render target of an existing style. */
    private function addStyle(string $name, string $likeStyle, int $canHaveChildren, string $description): void
    {
        $this->addSql(
            'INSERT INTO styles (name, id_style_groups, can_have_children, description, id_render_target) '
            . 'SELECT ?, src.id_style_groups, ?, ?, src.id_render_target FROM styles src '
            . 'WHERE src.name = ? AND NOT EXISTS (SELECT 1 FROM styles existing WHERE existing.name = ?) LIMIT 1',
            [$name, $canHaveChildren, $description, $likeStyle, $name],
        );
    }

    /** @param array<string, mixed>|null $config */
    private function addField(string $name, string $type, int $display, ?array $config): void
    {
        $this->addSql(
            'INSERT IGNORE INTO fields (name, id_field_types, display, config) '
            . 'SELECT ?, ft.id, ?, ? FROM field_types ft WHERE ft.name = ? LIMIT 1',
            [$name, $display, $config === null ? null : json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $type],
        );
    }

    private function linkField(string $style, string $field, string $default, string $title, string $help): void
    {
        $this->addSql(
            'INSERT IGNORE INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title) '
            . 'SELECT s.id, f.id, ?, ?, 0, 0, ? FROM styles s CROSS JOIN fields f WHERE s.name = ? AND f.name = ?',
            [$default, $help, $title, $style, $field],
        );
    }
}
