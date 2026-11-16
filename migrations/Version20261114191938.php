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
 * Typed `fixed_filter` on `entry-record` (F166): same JSON filter shape as a
 * list's `list_fixed_filter`, applied through DataQueryEngine / FormListBoundary.
 * A record outside it is treated like a missing record; its files answer 404.
 */
final class Version20261114191938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry-record.fixed_filter (typed JSON bound for public detail and current-file).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT IGNORE INTO fields (name, id_field_types, display, config) '
            . 'SELECT ?, ft.id, 0, ? FROM field_types ft WHERE ft.name = ? LIMIT 1',
            [
                'fixed_filter',
                json_encode([
                    'rows' => 5,
                    'placeholder' => '{"mode":"and","children":[{"column":"status","operator":"eq","value":"published"}]}',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'json',
            ],
        );
        $this->addSql(
            'INSERT IGNORE INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title) '
            . 'SELECT s.id, f.id, ?, ?, 0, 0, ? FROM styles s CROSS JOIN fields f WHERE s.name = ? AND f.name = ?',
            [
                '{"mode":"and","children":[]}',
                'JSON filter that bounds which record this detail may show (and whose files the current-file route may serve), whatever the URL names: {"mode":"and|or","children":[{"column","operator","value"}]}. A record outside it is treated like a missing record.',
                'Fixed filter',
                'entry-record',
                'fixed_filter',
            ],
        );
        // After migrate: invalidate CacheService CATEGORY_STYLES (style schema
        // lists Redis for up to 4h). app:test:reset-db / deploy should bump it.
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rfs FROM rel_fields_styles rfs '
            . 'INNER JOIN styles s ON s.id = rfs.id_styles '
            . 'INNER JOIN fields f ON f.id = rfs.id_fields '
            . 'WHERE s.name = ? AND f.name = ?',
            ['entry-record', 'fixed_filter'],
        );
        $this->addSql(
            'DELETE f FROM fields f '
            . 'WHERE f.name = ? AND NOT EXISTS ('
            . 'SELECT 1 FROM rel_fields_styles rfs WHERE rfs.id_fields = f.id'
            . ')',
            ['fixed_filter'],
        );
    }
}
