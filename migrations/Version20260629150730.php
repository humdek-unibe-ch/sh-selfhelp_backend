<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CMS field catalog cleanup (issue #56 field audit).
 *
 * 1. De-duplicates the `select` style: its renderer reads the `options` field,
 *    so the parallel `multi_select_data` ("Select Options") field is redundant
 *    and is removed (the `rel_fields_styles` link is dropped by the FK cascade).
 * 2. Removes three dead JSON field definitions (`web_combobox_data`, `items`,
 *    `labels`) that are attached to no style, page, page-type or section and are
 *    read by no renderer.
 * 3. Enriches the help text of a few structured JSON fields that lacked a usable
 *    inline example, so the section editor's help popover can show a copy-able
 *    example.
 *
 * No field is re-typed: a code audit of the renderers showed the existing
 * content-field types are correct (inline-rendered styles flatten block markup,
 * and the alert/profile messages are rendered as plain strings), so promoting
 * them to the rich `textarea` editor would corrupt or leak markup. The single
 * vs multi-line ergonomics are handled in the editor instead.
 */
final class Version20260629150730 extends AbstractMigration
{
    /** @var list<string> Dead field definitions with no references anywhere. */
    private const DEAD_FIELDS = ['multi_select_data', 'web_combobox_data', 'items', 'labels'];

    public function getDescription(): string
    {
        return 'De-duplicate select options, remove dead JSON fields, enrich structured-field help examples';
    }

    public function up(Schema $schema): void
    {
        // Deleting the field rows cascades to rel_fields_styles (FK ON DELETE
        // CASCADE), which removes the redundant select <-> multi_select_data link.
        $names = implode(', ', array_map(static fn (string $n): string => "'" . $n . "'", self::DEAD_FIELDS));
        $this->addSql(sprintf('DELETE FROM fields WHERE name IN (%s)', $names));

        $this->setHelp(
            'fields_map',
            'JSON array that selects, orders and relabels the columns shown. Each entry maps a column to a new header. '
            . 'Example: [{"field_name":"name","field_new_name":"Full name"},{"field_name":"email","field_new_name":"E-mail"}]'
        );
        $this->setHelp(
            'loop',
            'JSON array where each entry is a row object passed to the child sections; reference a row key with {{key}}. '
            . 'Example: [{"title":"First","value":"1"},{"title":"Second","value":"2"}]'
        );
        $this->setHelp(
            'web_carousel_embla_options',
            'Advanced Embla carousel options as JSON. Example: {"loop":true,"align":"center","slidesToScroll":1}. '
            . 'See https://www.embla-carousel.com/api/options/'
        );
    }

    public function down(Schema $schema): void
    {
        // Recreate the removed field definitions (all were `json`, translatable).
        foreach (self::DEAD_FIELDS as $name) {
            $this->addSql(sprintf(
                "INSERT INTO fields (name, id_field_types, display, config) "
                . "SELECT '%s', ft.id, 1, NULL FROM field_types ft WHERE ft.name='json'",
                $name
            ));
        }

        // Re-link multi_select_data to the select style.
        $this->addSql(
            "INSERT INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title) "
            . "SELECT s.id, f.id, NULL, 'JSON array of select options. Each option should have value and label', 0, 0, 'Select Options' "
            . "FROM styles s, fields f WHERE s.name='select' AND f.name='multi_select_data'"
        );

        $this->setHelp('fields_map', 'JSON array of column definitions controlling which fields are shown and with what labels.');
        $this->setHelp('loop', 'Json array object as each entry represnts a row which is passed to the children');
        $this->setHelp(
            'web_carousel_embla_options',
            'Sets advanced Embla carousel options as JSON. For more information check https://www.embla-carousel.com/api/options/'
        );
    }

    /**
     * Update the per-style help text for a field. These fields are each linked to
     * a single style, so matching by field name updates exactly one row.
     */
    private function setHelp(string $fieldName, string $help): void
    {
        $this->addSql(sprintf(
            "UPDATE rel_fields_styles rfs JOIN fields f ON f.id = rfs.id_fields SET rfs.help = '%s' WHERE f.name = '%s'",
            str_replace("'", "''", $help),
            $fieldName
        ));
    }
}
