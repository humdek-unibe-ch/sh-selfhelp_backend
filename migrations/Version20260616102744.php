<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616102744 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove use_mantine_style link from showUserInput — the style always renders as Mantine Table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "DELETE rfs FROM `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles
             JOIN `fields` f ON f.id = rfs.id_fields
             WHERE s.`name` = 'showUserInput' AND f.`name` = 'use_mantine_style'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO `rel_fields_styles`
                 (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
             SELECT s.id, f.id, '1', 'showUserInput always renders as a Mantine Table.', 0, 0, 'Use Mantine Style'
             FROM `styles` s, `fields` f
             WHERE s.`name` = 'showUserInput' AND f.`name` = 'use_mantine_style'"
        );
    }
}
