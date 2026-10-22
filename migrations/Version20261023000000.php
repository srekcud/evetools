<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account deletion: cascade characters and shared shopping lists, keep collaborative memberships, sales and corporation asset visibility without author';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE characters DROP CONSTRAINT FK_3A29410EA76ED395');
        $this->addSql('ALTER TABLE characters ADD CONSTRAINT FK_3A29410EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_project_members DROP CONSTRAINT FK_F14D59E0A76ED395');
        $this->addSql('ALTER TABLE group_industry_project_members ALTER user_id DROP NOT NULL');
        $this->addSql('ALTER TABLE group_industry_project_members ADD CONSTRAINT FK_F14D59E0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE corp_asset_visibility DROP CONSTRAINT FK_38FBB8A1771AF0CB');
        $this->addSql('ALTER TABLE corp_asset_visibility ALTER configured_by_id DROP NOT NULL');
        $this->addSql('ALTER TABLE corp_asset_visibility ADD CONSTRAINT FK_38FBB8A1771AF0CB FOREIGN KEY (configured_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT FK_4C462DD8D05A957B');
        $this->addSql('ALTER TABLE group_industry_sales ALTER recorded_by_id DROP NOT NULL');
        $this->addSql('ALTER TABLE group_industry_sales ADD CONSTRAINT FK_4C462DD8D05A957B FOREIGN KEY (recorded_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shared_shopping_lists DROP CONSTRAINT FK_B8CF3DB8B03A8386');
        $this->addSql('ALTER TABLE shared_shopping_lists ADD CONSTRAINT FK_B8CF3DB8B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_shopping_lists DROP CONSTRAINT FK_B8CF3DB8B03A8386');
        $this->addSql('ALTER TABLE shared_shopping_lists ADD CONSTRAINT FK_B8CF3DB8B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT FK_4C462DD8D05A957B');
        $this->addSql('DELETE FROM group_industry_sales WHERE recorded_by_id IS NULL');
        $this->addSql('ALTER TABLE group_industry_sales ALTER recorded_by_id SET NOT NULL');
        $this->addSql('ALTER TABLE group_industry_sales ADD CONSTRAINT FK_4C462DD8D05A957B FOREIGN KEY (recorded_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE corp_asset_visibility DROP CONSTRAINT FK_38FBB8A1771AF0CB');
        $this->addSql('DELETE FROM corp_asset_visibility WHERE configured_by_id IS NULL');
        $this->addSql('ALTER TABLE corp_asset_visibility ALTER configured_by_id SET NOT NULL');
        $this->addSql('ALTER TABLE corp_asset_visibility ADD CONSTRAINT FK_38FBB8A1771AF0CB FOREIGN KEY (configured_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_project_members DROP CONSTRAINT FK_F14D59E0A76ED395');
        $this->addSql('DELETE FROM group_industry_project_members WHERE user_id IS NULL');
        $this->addSql('ALTER TABLE group_industry_project_members ALTER user_id SET NOT NULL');
        $this->addSql('ALTER TABLE group_industry_project_members ADD CONSTRAINT FK_F14D59E0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE characters DROP CONSTRAINT FK_3A29410EA76ED395');
        $this->addSql('ALTER TABLE characters ADD CONSTRAINT FK_3A29410EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }
}
