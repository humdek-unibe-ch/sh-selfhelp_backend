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
 * Rename the remaining camelCase CMS style names to kebab-case.
 *
 * The CMS style catalog is a cross-repo contract: the backend `styles` rows,
 * `@selfhelp/shared` (`STYLE_REGISTRY` + the `style_name` discriminator), the
 * frontend `BasicStyle` dispatcher, and the mobile renderers must all agree on
 * the style name string. The catalog was already kebab-case except a handful of
 * camelCase hold-overs; this aligns the `styles.name` rows with the kebab-case
 * names shipped in `@selfhelp/shared` 1.8.0 and the frontend dispatcher.
 *
 * Sections reference styles by `id_styles` (FK), not by the name string, so
 * this is a metadata rename, not a content migration. The `styles_fields`
 * links are FK-based as well and are unaffected.
 *
 * Each UPDATE is name-guarded, so it is a no-op for any name not present on a
 * given instance (older installs may not carry every style). PHP look-ups of
 * these names were updated in the same change (StyleNames::STYLE_SHOW_USER_INPUT,
 * PageService::FALLBACK_CHECK_KEYWORDS, AdminSectionUtilityService).
 */
final class Version20260618120000 extends AbstractMigration
{
    /**
     * camelCase => kebab-case style-name rename map. Keep in lockstep with
     * `@selfhelp/shared` and the frontend `BasicStyle` dispatcher.
     */
    private const RENAMES = [
        'resetPassword'     => 'reset-password',
        'twoFactorAuth'     => 'two-factor-auth',
        'noAccess'          => 'no-access',
        'notFound'          => 'not-found',
        'entryList'         => 'entry-list',
        'entryRecord'       => 'entry-record',
        'entryRecordDelete' => 'entry-record-delete',
        'showUserInput'     => 'show-user-input',
        'refContainer'      => 'ref-container',
        'dataContainer'     => 'data-container',
    ];

    public function getDescription(): string
    {
        return 'Rename remaining camelCase CMS style names to kebab-case (lockstep with @selfhelp/shared 1.8.0).';
    }

    public function up(Schema $schema): void
    {
        // Hardcoded constant values (no user input), so inline quoting is safe
        // and matches the raw-SQL style used by the other migrations.
        foreach (self::RENAMES as $from => $to) {
            $this->addSql(sprintf(
                "UPDATE `styles` SET `name` = '%s' WHERE `name` = '%s'",
                $to,
                $from,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RENAMES as $from => $to) {
            $this->addSql(sprintf(
                "UPDATE `styles` SET `name` = '%s' WHERE `name` = '%s'",
                $from,
                $to,
            ));
        }
    }
}
