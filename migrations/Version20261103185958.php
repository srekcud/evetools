<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103185958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mining payments and the archive columns of mining operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mining_payments (id UUID NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, paid_by_name VARCHAR(255) DEFAULT NULL, position SMALLINT NOT NULL, kind VARCHAR(30) NOT NULL, payer_name VARCHAR(255) DEFAULT NULL, beneficiary_name VARCHAR(255) DEFAULT NULL, amount DOUBLE PRECISION NOT NULL, paid_by_id UUID DEFAULT NULL, operation_id UUID NOT NULL, payer_member_id UUID DEFAULT NULL, beneficiary_member_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9604ACDD7F9BC654 ON mining_payments (paid_by_id)');
        $this->addSql('CREATE INDEX IDX_9604ACDD44AC3583 ON mining_payments (operation_id)');
        $this->addSql('CREATE INDEX IDX_9604ACDD30022BC7 ON mining_payments (payer_member_id)');
        $this->addSql('CREATE INDEX IDX_9604ACDD3A7EFF3F ON mining_payments (beneficiary_member_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9604ACDD44AC3583462CE4F5 ON mining_payments (operation_id, position)');
        $this->addSql('ALTER TABLE mining_payments ADD CONSTRAINT FK_9604ACDD7F9BC654 FOREIGN KEY (paid_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_payments ADD CONSTRAINT FK_9604ACDD44AC3583 FOREIGN KEY (operation_id) REFERENCES mining_operations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_payments ADD CONSTRAINT FK_9604ACDD30022BC7 FOREIGN KEY (payer_member_id) REFERENCES mining_operation_members (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_payments ADD CONSTRAINT FK_9604ACDD3A7EFF3F FOREIGN KEY (beneficiary_member_id) REFERENCES mining_operation_members (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operations ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD archived_by_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_payments DROP CONSTRAINT FK_9604ACDD7F9BC654');
        $this->addSql('ALTER TABLE mining_payments DROP CONSTRAINT FK_9604ACDD44AC3583');
        $this->addSql('ALTER TABLE mining_payments DROP CONSTRAINT FK_9604ACDD30022BC7');
        $this->addSql('ALTER TABLE mining_payments DROP CONSTRAINT FK_9604ACDD3A7EFF3F');
        $this->addSql('DROP TABLE mining_payments');
        $this->addSql('ALTER TABLE mining_operations DROP archived_at');
        $this->addSql('ALTER TABLE mining_operations DROP archived_by_name');
    }
}
