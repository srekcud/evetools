<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125210126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pve_expenses ADD contract_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_expenses ADD transaction_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_9299B28AA76ED3952576E0FD ON pve_expenses (user_id, contract_id)');
        $this->addSql('CREATE INDEX IDX_9299B28AA76ED3952FC0CB0F ON pve_expenses (user_id, transaction_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_9299B28AA76ED3952576E0FD');
        $this->addSql('DROP INDEX IDX_9299B28AA76ED3952FC0CB0F');
        $this->addSql('ALTER TABLE pve_expenses DROP contract_id');
        $this->addSql('ALTER TABLE pve_expenses DROP transaction_id');
    }
}
