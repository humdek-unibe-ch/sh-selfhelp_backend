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
 * Audit rows outlive the person. Removing a user (or cleaning their data) keeps
 * the `transactions` and `data_access_audits` rows and clears only the link to
 * the user (ON DELETE SET NULL, concept rule "removing a user removes their
 * data", F24).
 *
 * Before this, `transactions.id_users` was ON DELETE CASCADE (the history
 * vanished with the user) and `data_access_audits.id_users` was NOT NULL with no
 * delete rule, which made deleting any user who had an audit row fail.
 */
final class Version20261111083203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit rows keep existing when their user is removed: transactions and data_access_audits id_users become ON DELETE SET NULL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_access_audits DROP FOREIGN KEY `fk_data_access_audits_id_users`');
        $this->addSql('ALTER TABLE data_access_audits CHANGE id_users id_users INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_access_audits ADD CONSTRAINT FK_318C9F74FA06E4D9 FOREIGN KEY (id_users) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY `fk_transactions_id_users`');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CFA06E4D9 FOREIGN KEY (id_users) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // The old column cannot hold a row without a person, so those audit
        // rows are dropped (transactions can keep a NULL user).
        $this->addSql('DELETE FROM data_access_audits WHERE id_users IS NULL');
        $this->addSql('ALTER TABLE data_access_audits DROP FOREIGN KEY FK_318C9F74FA06E4D9');
        $this->addSql('ALTER TABLE data_access_audits CHANGE id_users id_users INT NOT NULL');
        $this->addSql('ALTER TABLE data_access_audits ADD CONSTRAINT `fk_data_access_audits_id_users` FOREIGN KEY (id_users) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_EAA81A4CFA06E4D9');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT `fk_transactions_id_users` FOREIGN KEY (id_users) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
    }
}
