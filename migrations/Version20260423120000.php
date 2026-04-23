<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retypes the `mantine_text_gradient` field from `textarea` to `json`.
 *
 * The SQL seed script was updated so fresh installations register the field
 * with the correct type. Existing databases still store the legacy `textarea`
 * type, so this migration aligns them with the seed and lets the admin UI
 * render the proper JSON editor for the field.
 */
final class Version20260423120000 extends AbstractMigration
{
    /**
     * Describe the migration for Doctrine tooling.
     */
    public function getDescription(): string
    {
        return 'Retype mantine_text_gradient field from textarea to json';
    }

    /**
     * Apply the field retype.
     *
     * @param Schema $schema
     *   The Doctrine schema object provided by the migration runtime.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE `fields` f
             JOIN `fieldType` ft_json ON ft_json.name = 'json'
             JOIN `fieldType` ft_textarea ON ft_textarea.name = 'textarea'
             SET f.id_type = ft_json.id
             WHERE f.name = 'mantine_text_gradient'
               AND f.id_type = ft_textarea.id"
        );
    }

    /**
     * Revert the field retype.
     *
     * @param Schema $schema
     *   The Doctrine schema object provided by the migration runtime.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE `fields` f
             JOIN `fieldType` ft_json ON ft_json.name = 'json'
             JOIN `fieldType` ft_textarea ON ft_textarea.name = 'textarea'
             SET f.id_type = ft_textarea.id
             WHERE f.name = 'mantine_text_gradient'
               AND f.id_type = ft_json.id"
        );
    }
}
