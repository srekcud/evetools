<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the group industry tables to the industry project participants, products, BOM lines, contributions and sales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE industry_project_bom_lines (
              id UUID NOT NULL,
              type_id INT NOT NULL,
              type_name VARCHAR(255) NOT NULL,
              required_quantity INT NOT NULL,
              fulfilled_quantity INT NOT NULL,
              estimated_price DOUBLE PRECISION DEFAULT NULL,
              is_job BOOLEAN NOT NULL,
              job_group VARCHAR(20) DEFAULT NULL,
              activity_type VARCHAR(20) DEFAULT NULL,
              parent_type_id INT DEFAULT NULL,
              me_level INT DEFAULT NULL,
              te_level INT DEFAULT NULL,
              runs INT DEFAULT NULL,
              project_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_C4DE6CC5166D1F9C ON industry_project_bom_lines (project_id)');
        $this->addSql('CREATE INDEX IDX_C4DE6CC5C54C8C93 ON industry_project_bom_lines (type_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE industry_project_contributions (
              id UUID NOT NULL,
              type VARCHAR(20) NOT NULL,
              quantity INT NOT NULL,
              estimated_value DOUBLE PRECISION NOT NULL,
              status VARCHAR(10) NOT NULL,
              is_auto_detected BOOLEAN NOT NULL,
              is_verified BOOLEAN NOT NULL,
              verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              note TEXT DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              project_id UUID NOT NULL,
              member_id UUID NOT NULL,
              bom_item_id UUID DEFAULT NULL,
              reviewed_by_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_74E225D0EDFACB51 ON industry_project_contributions (bom_item_id)');
        $this->addSql('CREATE INDEX IDX_74E225D0FC6B21F1 ON industry_project_contributions (reviewed_by_id)');
        $this->addSql('CREATE INDEX IDX_74E225D0166D1F9C ON industry_project_contributions (project_id)');
        $this->addSql('CREATE INDEX IDX_74E225D07597D3FE ON industry_project_contributions (member_id)');
        $this->addSql('CREATE INDEX IDX_74E225D07B00651C ON industry_project_contributions (status)');
        $this->addSql(<<<'SQL'
            CREATE TABLE industry_project_participants (
              id UUID NOT NULL,
              role VARCHAR(10) NOT NULL,
              status VARCHAR(10) NOT NULL,
              joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              project_id UUID NOT NULL,
              user_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_C7C15451166D1F9C ON industry_project_participants (project_id)');
        $this->addSql('CREATE INDEX IDX_C7C15451A76ED395 ON industry_project_participants (user_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_C7C15451166D1F9CA76ED395 ON industry_project_participants (project_id, user_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE industry_project_products (
              id UUID NOT NULL,
              type_id INT NOT NULL,
              type_name VARCHAR(255) NOT NULL,
              me_level INT NOT NULL,
              te_level INT NOT NULL,
              runs INT NOT NULL,
              sort_order INT NOT NULL,
              project_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_779D3C21166D1F9C ON industry_project_products (project_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE industry_project_sales (
              id UUID NOT NULL,
              type_id INT NOT NULL,
              type_name VARCHAR(255) NOT NULL,
              quantity INT NOT NULL,
              unit_price DOUBLE PRECISION NOT NULL,
              total_price DOUBLE PRECISION NOT NULL,
              venue VARCHAR(255) DEFAULT NULL,
              sold_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              project_id UUID NOT NULL,
              recorded_by_id UUID DEFAULT NULL,
              seller_character_id UUID DEFAULT NULL,
              wallet_transaction_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_C95DAA69D05A957B ON industry_project_sales (recorded_by_id)');
        $this->addSql('CREATE INDEX IDX_C95DAA69449E3089 ON industry_project_sales (seller_character_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C95DAA69924C1837 ON industry_project_sales (wallet_transaction_id)');
        $this->addSql('CREATE INDEX IDX_C95DAA69166D1F9C ON industry_project_sales (project_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_bom_lines
            ADD
              CONSTRAINT FK_C4DE6CC5166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_contributions
            ADD
              CONSTRAINT FK_74E225D0166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_contributions
            ADD
              CONSTRAINT FK_74E225D07597D3FE FOREIGN KEY (member_id) REFERENCES industry_project_participants (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_contributions
            ADD
              CONSTRAINT FK_74E225D0EDFACB51 FOREIGN KEY (bom_item_id) REFERENCES industry_project_bom_lines (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_contributions
            ADD
              CONSTRAINT FK_74E225D0FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_participants
            ADD
              CONSTRAINT FK_C7C15451166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_participants
            ADD
              CONSTRAINT FK_C7C15451A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_products
            ADD
              CONSTRAINT FK_779D3C21166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_sales
            ADD
              CONSTRAINT FK_C95DAA69166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_sales
            ADD
              CONSTRAINT FK_C95DAA69D05A957B FOREIGN KEY (recorded_by_id) REFERENCES users (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_sales
            ADD
              CONSTRAINT FK_C95DAA69449E3089 FOREIGN KEY (seller_character_id) REFERENCES characters (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              industry_project_sales
            ADD
              CONSTRAINT FK_C95DAA69924C1837 FOREIGN KEY (wallet_transaction_id) REFERENCES cached_wallet_transactions (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
        $this->addSql('ALTER TABLE group_industry_bom_items DROP CONSTRAINT fk_1f9191d1166d1f9c');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT fk_3318ea2f7597d3fe');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT fk_3318ea2fedfacb51');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT fk_3318ea2ffc6b21f1');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT fk_3318ea2f166d1f9c');
        $this->addSql('ALTER TABLE group_industry_project_items DROP CONSTRAINT fk_b76a1402166d1f9c');
        $this->addSql('ALTER TABLE group_industry_project_members DROP CONSTRAINT fk_f14d59e0a76ed395');
        $this->addSql('ALTER TABLE group_industry_project_members DROP CONSTRAINT fk_f14d59e0166d1f9c');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT fk_4c462dd8924c1837');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT fk_4c462dd8166d1f9c');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT fk_4c462dd8d05a957b');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT fk_4c462dd8449e3089');
        $this->addSql('DROP TABLE group_industry_bom_items');
        $this->addSql('DROP TABLE group_industry_contributions');
        $this->addSql('DROP TABLE group_industry_project_items');
        $this->addSql('DROP TABLE group_industry_project_members');
        $this->addSql('DROP TABLE group_industry_sales');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The group industry tables are replaced by empty industry project tables.');
    }
}
