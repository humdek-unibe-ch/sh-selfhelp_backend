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
 * Card field cleanup: drop the redundant web-only `web_card_padding` link.
 *
 * The `card` style already extends the portable spacing contract
 * (`shared_spacing`), whose padding side (`pt`/`pb`/`ps`/`pe`) renders on web AND
 * mobile. A second, web-only `web_card_padding` (Mantine `padding` prop) is a
 * duplicate padding control that confuses authors, so it is unlinked from `card`.
 * The card keeps a sensible fixed Mantine inner padding in the renderer
 * (`padding="md"`, also used for the Card.Section image bleed) and authors tune
 * padding through `shared_spacing`.
 *
 * The `web_card_padding` FIELD itself is NOT dropped — it stays linked to
 * `validate` (intentionally web-only there). Only the `card` link + any authored
 * card section values for it are removed (FK-safe).
 *
 * `down()` re-links it to `card` with the original default/title/help.
 */
final class Version20260619205908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Card cleanup: unlink redundant web-only web_card_padding from card (shared_spacing already covers padding cross-platform).';
    }

    public function up(Schema $schema): void
    {
        // Remove any authored card-section values for the field first (FK-safe).
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_card_padding')
               AND id_sections IN (
                   SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'card')
               )"
        );
        // Drop the card <-> web_card_padding catalog link.
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_card_padding')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'card')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, 'sm',
                    'Sets the padding of the card. For more information check https://mantine.dev/core/card',
                    0, 0, 'Padding'
             FROM `styles` s, `fields` f
             WHERE s.`name` = 'card' AND f.`name` = 'web_card_padding'"
        );
    }
}
