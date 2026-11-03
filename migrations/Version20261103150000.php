<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_reprocessing_profiles to store the reprocessing yield computed from character skills and location';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_reprocessing_profiles (id UUID NOT NULL, ore_processing_skill_type_id INT NOT NULL, location VARCHAR(20) NOT NULL, rig_tier VARCHAR(10) DEFAULT NULL, security VARCHAR(10) DEFAULT NULL, implant_percent SMALLINT NOT NULL, reprocessing_level SMALLINT DEFAULT NULL, reprocessing_efficiency_level SMALLINT DEFAULT NULL, ore_processing_level SMALLINT DEFAULT NULL, reprocess_yield DOUBLE PRECISION DEFAULT NULL, failure VARCHAR(20) DEFAULT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7F098DF0A76ED395 ON user_reprocessing_profiles (user_id)');
        $this->addSql('CREATE INDEX IDX_7F098DF01136BE75 ON user_reprocessing_profiles (character_id)');
        $this->addSql('ALTER TABLE user_reprocessing_profiles ADD CONSTRAINT FK_7F098DF0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_reprocessing_profiles ADD CONSTRAINT FK_7F098DF01136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_reprocessing_profiles DROP CONSTRAINT FK_7F098DF0A76ED395');
        $this->addSql('ALTER TABLE user_reprocessing_profiles DROP CONSTRAINT FK_7F098DF01136BE75');
        $this->addSql('DROP TABLE user_reprocessing_profiles');
    }
}
