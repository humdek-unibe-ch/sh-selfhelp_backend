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
 * Distinguish button link targets in the section inspector: both `page_keyword`
 * and `url` previously shared the title "URL", which made authors write paths
 * into the wrong field (page_keyword defaults to "#" and shadows url at render).
 */
final class Version20260706221200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Button style: rename inspector titles for page_keyword vs url.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles
             JOIN `fields` f ON f.id = rfs.id_fields
             SET rfs.title = 'Internal page',
                 rfs.help = 'Select a page keyword for an internal CMS link. Leave empty or # to use Path / external URL instead.',
                 rfs.default_value = ''
             WHERE s.name = 'button' AND f.name = 'page_keyword'"
        );
        $this->addSql(
            "UPDATE `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles
             JOIN `fields` f ON f.id = rfs.id_fields
             SET rfs.title = 'Path / external URL',
                 rfs.help = 'Absolute path (/…) or external URL when Internal page is unset. Used for profile/back links and mailto:.'
             WHERE s.name = 'button' AND f.name = 'url'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles
             JOIN `fields` f ON f.id = rfs.id_fields
             SET rfs.title = 'URL',
                 rfs.help = 'Select a page keyword to link to. For more information check https://mantine.dev/core/button',
                 rfs.default_value = '#'
             WHERE s.name = 'button' AND f.name = 'page_keyword'"
        );
        $this->addSql(
            "UPDATE `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles
             JOIN `fields` f ON f.id = rfs.id_fields
             SET rfs.title = 'URL',
                 rfs.help = 'External URL to open when the button is a link and no internal page is selected.'
             WHERE s.name = 'button' AND f.name = 'url'"
        );
    }
}
