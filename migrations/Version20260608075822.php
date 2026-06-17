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
 * Rename three system page keywords from underscore to hyphen to match the
 * URL convention already used by every other auth page and the frontend route
 * map:
 *
 *   reset_password  → reset-password   (/reset stays, keyword aligns)
 *   no_access       → no-access
 *   no_access_guest → no-access-guest
 *
 * Also aligns the URL of `reset_password` (/reset → /reset-password) so all
 * three pages follow the `keyword == url-slug` pattern the frontend expects.
 *
 * Sections seeded by Version20260605134800 reference page keywords; their
 * section names (`no_access-sys`, `no_access_guest-sys`) are internal CMS
 * identifiers and are NOT changed — only the `pages.keyword` column.
 */
final class Version20260608075822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename system page keywords from underscore to hyphen: reset_password→reset-password, no_access→no-access, no_access_guest→no-access-guest.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE `pages` SET `keyword` = 'reset-password', `url` = '/reset-password' WHERE `keyword` = 'reset_password'");
        $this->addSql("UPDATE `pages` SET `keyword` = 'no-access',       `url` = '/no-access'       WHERE `keyword` = 'no_access'");
        $this->addSql("UPDATE `pages` SET `keyword` = 'no-access-guest', `url` = '/no-access-guest' WHERE `keyword` = 'no_access_guest'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE `pages` SET `keyword` = 'no_access_guest', `url` = '/no-access-guest' WHERE `keyword` = 'no-access-guest'");
        $this->addSql("UPDATE `pages` SET `keyword` = 'no_access',       `url` = '/no-access'       WHERE `keyword` = 'no_access'");
        $this->addSql("UPDATE `pages` SET `keyword` = 'reset_password',  `url` = '/reset'           WHERE `keyword` = 'reset-password'");
    }
}
