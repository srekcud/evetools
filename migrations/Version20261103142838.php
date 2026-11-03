<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103142838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mining operations with their members and enrolled characters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mining_operation_characters (id UUID NOT NULL, enrolled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, member_id UUID NOT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B29AEC847597D3FE ON mining_operation_characters (member_id)');
        $this->addSql('CREATE INDEX IDX_B29AEC841136BE75 ON mining_operation_characters (character_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B29AEC847597D3FE1136BE75 ON mining_operation_characters (member_id, character_id)');
        $this->addSql('CREATE TABLE mining_operation_members (id UUID NOT NULL, role VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, operation_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D7F31BA444AC3583 ON mining_operation_members (operation_id)');
        $this->addSql('CREATE INDEX IDX_D7F31BA4A76ED395 ON mining_operation_members (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D7F31BA444AC3583A76ED395 ON mining_operation_members (operation_id, user_id)');
        $this->addSql('CREATE TABLE mining_operations (id UUID NOT NULL, label VARCHAR(100) NOT NULL, planned_start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(20) NOT NULL, visibility VARCHAR(20) NOT NULL, split_mode VARCHAR(30) NOT NULL, corporation_id BIGINT DEFAULT NULL, join_code VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_494FEAB27E3C61F9 ON mining_operations (owner_id)');
        $this->addSql('CREATE INDEX IDX_494FEAB2518E4300B26853697B00651C ON mining_operations (visibility, corporation_id, status)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_494FEAB2E64D7D01 ON mining_operations (join_code)');
        $this->addSql('ALTER TABLE mining_operation_characters ADD CONSTRAINT FK_B29AEC847597D3FE FOREIGN KEY (member_id) REFERENCES mining_operation_members (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operation_characters ADD CONSTRAINT FK_B29AEC841136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operation_members ADD CONSTRAINT FK_D7F31BA444AC3583 FOREIGN KEY (operation_id) REFERENCES mining_operations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operation_members ADD CONSTRAINT FK_D7F31BA4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operations ADD CONSTRAINT FK_494FEAB27E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operation_characters DROP CONSTRAINT FK_B29AEC847597D3FE');
        $this->addSql('ALTER TABLE mining_operation_characters DROP CONSTRAINT FK_B29AEC841136BE75');
        $this->addSql('ALTER TABLE mining_operation_members DROP CONSTRAINT FK_D7F31BA444AC3583');
        $this->addSql('ALTER TABLE mining_operation_members DROP CONSTRAINT FK_D7F31BA4A76ED395');
        $this->addSql('ALTER TABLE mining_operations DROP CONSTRAINT FK_494FEAB27E3C61F9');
        $this->addSql('DROP TABLE mining_operation_characters');
        $this->addSql('DROP TABLE mining_operation_members');
        $this->addSql('DROP TABLE mining_operations');
    }
}
