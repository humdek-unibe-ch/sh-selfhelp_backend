<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enable inline rich-text on the shared `text` content field.
 *
 * The `text` content field (used by the `text` and `highlight` display styles)
 * was a plain multi-line `textarea`, so an author could not make a label bold.
 * This switches its editor to `markdown-inline`, which exposes the Ctrl+B / I / U
 * (+ link) shortcuts in the section inspector. The web and mobile `text`
 * renderers preserve that safe inline subset — web via
 * `sanitizeHtmlForParsing` + `html-react-parser`, mobile via `parseInlineRich` +
 * `<InlineText>` — so a bold label authored on the web now also renders bold on
 * the mobile app, which is the whole point of the change.
 *
 * Only the editor (and what an author may type) changes; existing stored values
 * are untouched and still render. `down()` restores the `textarea` editor.
 */
final class Version20260622100253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Switch the shared `text` content field (text + highlight styles) from textarea to markdown-inline so authors can apply inline bold/italic/underline that renders on web and mobile.";
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->fieldTypeExists('markdown-inline'),
            "Refusing: required field_type 'markdown-inline' is missing."
        );

        $this->addSql(
            "UPDATE `fields`
                SET id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'markdown-inline')
              WHERE `name` = 'text'
                AND id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'textarea')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->fieldTypeExists('textarea'),
            "Refusing: required field_type 'textarea' is missing."
        );

        $this->addSql(
            "UPDATE `fields`
                SET id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'textarea')
              WHERE `name` = 'text'
                AND id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'markdown-inline')"
        );
    }

    private function fieldTypeExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `field_types` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
