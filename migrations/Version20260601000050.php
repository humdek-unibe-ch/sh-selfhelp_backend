<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601000050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete section with id 69';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM selfhelp2.sections WHERE id = 69');
    }
    public function down(Schema $schema): void {}
}
