<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520093222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set display = 0 for mail global config fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            UPDATE fields SET display = 0 WHERE id IN (238, 236, 239, 237)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            UPDATE fields SET display = 1 WHERE id IN (238, 236, 239, 237)
        ");
    }
}
