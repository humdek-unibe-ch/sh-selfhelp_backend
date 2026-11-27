<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\MigrationRoundTripTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Round-trip for Version20261126133108 (contract C14): `up()` creates `plugin_settings`, `down()` drops it
 * (and with it every stored setting: a rollback of a table-creating migration is destructive by nature).
 *
 * The table is keyed by the plugin's manifest id and has **no foreign key to `plugins`**: that is what lets an
 * uninstall keep the rows while the purge (a listener) deletes them. The test pins that, the unique key over
 * `(plugin_id, setting_key)`, and the `SET NULL` of the editor column, then proves the ORM mapping matches the
 * migrated schema (`doctrine:schema:validate`).
 */
#[Group('migration')]
final class Version20261126133108RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261126133108';
    private const TABLE = 'plugin_settings';
    private const PLUGIN = 'qa-roundtrip-plugin';
    private const EMAIL = 'qa-settings-roundtrip@selfhelp.test';

    public function testTheSettingsTableIsCreatedDroppedAndCreatedAgain(): void
    {
        $this->migrate(self::VERSION);
        self::assertSame(1, $this->tables(), 'up(): the table exists.');
        self::assertSame(0, $this->queryInt('SELECT COUNT(*) FROM plugin_settings'), 'up(): it starts empty.');
        foreach (['id', 'plugin_id', 'setting_key', 'value', 'is_secret', 'updated_at', 'id_users_updated'] as $column) {
            self::assertSame(1, $this->columns($column), "up(): the column {$column} exists.");
        }
        self::assertSame(7, $this->columns(), 'up(): and there are no other columns.');

        $this->migrate('prev'); // down()
        self::assertSame(0, $this->tables(), 'down(): the table is gone.');

        $this->migrate(self::VERSION); // up() again
        self::assertSame(1, $this->tables(), 'up() again: the table is back.');
        self::assertSame(0, $this->queryInt('SELECT COUNT(*) FROM plugin_settings'), 'up() again: it is empty (down() deleted what was stored).');

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }

    public function testTheRowsAreKeyedByThePluginIdWithoutAForeignKeyToPlugins(): void
    {
        $this->migrate(self::VERSION);

        self::assertSame(
            0,
            $this->queryInt("SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'plugin_settings' AND referenced_table_name = 'plugins'"),
            'There is no foreign key to plugins: an uninstall must not cascade.'
        );
        self::assertSame(
            0,
            $this->queryInt("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plugin_settings' AND column_name = 'id_plugins'"),
            'The rows are keyed by the manifest id, not by the id of the plugins row.'
        );
        self::assertSame(
            2,
            $this->queryInt("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'plugin_settings' AND index_name = 'uq_plugin_settings_plugin_key' AND non_unique = 0 AND column_name IN ('plugin_id', 'setting_key')"),
            'One value per plugin and setting key: a unique key over both columns.'
        );
        self::assertSame(
            1,
            $this->queryInt("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'plugin_settings' AND referenced_table_name = 'users' AND delete_rule = 'SET NULL'"),
            'The editor column points at users and is set to null when the user is deleted.'
        );
    }

    public function testDeletingThePluginRowKeepsItsSettingsAndDeletingTheUserOnlyForgetsWhoSavedThem(): void
    {
        $this->migrate(self::VERSION);
        $this->execSql(
            'INSERT INTO plugins (plugin_id, name, version, plugin_api_version, manifest_json, capabilities_json, installed_at, updated_at)'
            . " VALUES ('" . self::PLUGIN . "', 'QA roundtrip', '1.0.0', '0.2.0', '{}', '[]', NOW(), NOW())"
        );
        $this->execSql("INSERT INTO users (email) VALUES ('" . self::EMAIL . "')");
        $this->execSql(
            'INSERT INTO plugin_settings (plugin_id, setting_key, value, is_secret, updated_at, id_users_updated)'
            . " VALUES ('" . self::PLUGIN . "', 'api_key', 'enc:v1:AAAA', 1, NOW(), (SELECT id FROM users WHERE email = '" . self::EMAIL . "'))"
        );
        $this->execSql(
            'INSERT INTO plugin_settings (plugin_id, setting_key, value, is_secret, updated_at)'
            . " VALUES ('" . self::PLUGIN . "', 'label', 'kept', 0, NOW())"
        );
        self::assertSame(2, $this->rows(), 'Setup: two stored values.');

        // What an uninstall does to the plugins table.
        $this->execSql("DELETE FROM plugins WHERE plugin_id = '" . self::PLUGIN . "'");
        self::assertSame(2, $this->rows(), 'The settings stay when the plugin row is deleted.');

        // Deleting the user who saved a value keeps the value.
        $this->execSql("DELETE FROM users WHERE email = '" . self::EMAIL . "'");
        self::assertSame(2, $this->rows(), 'The settings stay when their editor is deleted.');
        self::assertSame(
            2,
            $this->queryInt("SELECT COUNT(*) FROM plugin_settings WHERE plugin_id = '" . self::PLUGIN . "' AND id_users_updated IS NULL"),
            'Only the reference to the editor is cleared.'
        );

        $this->migrate('prev'); // down(), with data in place
        self::assertSame(0, $this->tables(), 'down(): the table is dropped together with its rows.');

        $this->migrate('latest');
        $this->assertSchemaInSync('after the data round-trip of ' . self::VERSION);
    }

    private function tables(): int
    {
        return $this->queryInt("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "'");
    }

    /** How many columns the table has, or how many columns of that name (0 or 1). */
    private function columns(?string $name = null): int
    {
        $filter = $name === null ? '' : " AND column_name = '{$name}'";

        return $this->queryInt("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "'{$filter}");
    }

    private function rows(): int
    {
        return $this->queryInt('SELECT COUNT(*) FROM plugin_settings');
    }
}
