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
 * Clarify the refContainer style description.
 *
 * refContainer is a structural, semantic container for reusable section
 * blocks. It does not introduce any visual styling, layout behaviour,
 * debug UI, or presentation fields of its own. The previous description
 * ("Wrap other styles that later can be used in different place. It can
 * be used for creating resusable blocks.") contained a typo and implied
 * a visual role. The corrected description makes the transparent,
 * pass-through nature explicit so editors do not confuse it with layout
 * wrappers like box, container, or paper.
 */
final class Version20260608124537 extends AbstractMigration
{
    private const OLD_DESCRIPTION = 'Wrap other styles that later can be used in different place. It can be used for creating resusable blocks.';
    private const NEW_DESCRIPTION = 'Structural container for reusable section blocks. Passes children through without adding any visual styling, layout, or presentation of its own. Use this style when a section must be referenced from multiple pages.';

    public function getDescription(): string
    {
        return 'Correct refContainer style description: structural/transparent container, not a visual wrapper.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE `styles` SET `description` = :new WHERE `name` = 'refContainer'",
            ['new' => self::NEW_DESCRIPTION]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE `styles` SET `description` = :old WHERE `name` = 'refContainer'",
            ['old' => self::OLD_DESCRIPTION]
        );
    }
}
