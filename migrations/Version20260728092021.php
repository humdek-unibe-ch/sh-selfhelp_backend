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
 * Repoint asset references stored in CMS content at the ACL-enforced delivery
 * route.
 *
 * Asset pickers (`select-image` / `select-video`) used to store the raw storage
 * path `uploads/assets/<folder>/<file>` as the field value, which the frontend
 * rendered as a document-root-relative URL. Now that assets live outside the
 * document root and are served by GET /cms-api/v1/assets/{folder}/{filename},
 * those stored values must carry the new prefix or the images 404.
 *
 * Only the exact legacy prefix is rewritten, so a value that is already a
 * delivery URL, an external URL, or an interpolation token is left untouched.
 * The pickers themselves now offer the delivery URL directly
 * ({@see \App\Service\CMS\Admin\SectionFieldService}), so this is a one-off
 * catch-up for content authored before the change.
 */
final class Version20260728092021 extends AbstractMigration
{
    private const LEGACY_PREFIX = 'uploads/assets/';
    private const DELIVERY_PREFIX = '/cms-api/v1/assets/';

    public function getDescription(): string
    {
        return 'Rewrite stored uploads/assets/ content references to the ACL-enforced delivery route.';
    }

    public function up(Schema $schema): void
    {
        // Both the bare path and the leading-slash variant were reachable
        // statically, so normalise each onto the delivery prefix.
        $this->addSql(
            'UPDATE sections_fields_translation SET content = CONCAT(?, SUBSTRING(content, CHAR_LENGTH(?) + 2)) '
            . 'WHERE content LIKE ?',
            [self::DELIVERY_PREFIX, self::LEGACY_PREFIX, '/' . self::LEGACY_PREFIX . '%']
        );
        $this->addSql(
            'UPDATE sections_fields_translation SET content = CONCAT(?, SUBSTRING(content, CHAR_LENGTH(?) + 1)) '
            . 'WHERE content LIKE ?',
            [self::DELIVERY_PREFIX, self::LEGACY_PREFIX, self::LEGACY_PREFIX . '%']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE sections_fields_translation SET content = CONCAT(?, SUBSTRING(content, CHAR_LENGTH(?) + 1)) '
            . 'WHERE content LIKE ?',
            [self::LEGACY_PREFIX, self::DELIVERY_PREFIX, self::DELIVERY_PREFIX . '%']
        );
    }
}
