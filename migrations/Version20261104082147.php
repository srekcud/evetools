<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104082147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE corporation_wallet_donations (id UUID NOT NULL, corporation_id BIGINT NOT NULL, journal_id BIGINT NOT NULL, first_party_id BIGINT NOT NULL, date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, amount DOUBLE PRECISION NOT NULL, reason TEXT DEFAULT NULL, attributed_character_id BIGINT DEFAULT NULL, attributed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, attributed_by_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_347FFEDAE7B0B596 ON corporation_wallet_donations (attributed_by_user_id)');
        $this->addSql('CREATE INDEX IDX_347FFEDAB268536995242202 ON corporation_wallet_donations (corporation_id, first_party_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_347FFEDAB2685369478E8802 ON corporation_wallet_donations (corporation_id, journal_id)');
        $this->addSql('CREATE TABLE moon_observer_records (id UUID NOT NULL, corporation_id BIGINT NOT NULL, observer_id BIGINT NOT NULL, character_id BIGINT NOT NULL, type_id INT NOT NULL, last_updated DATE NOT NULL, quantity BIGINT NOT NULL, recorded_corporation_id BIGINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E14C9E48B2685369A017AABF ON moon_observer_records (corporation_id, last_updated)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E14C9E4883F4F5461136BE75C54C8C93A017AABF ON moon_observer_records (observer_id, character_id, type_id, last_updated)');
        $this->addSql('CREATE TABLE moon_tax_refinery_settings (id UUID NOT NULL, corporation_id BIGINT NOT NULL, structure_id BIGINT NOT NULL, open_to_alliance BOOLEAN NOT NULL, period VARCHAR(10) NOT NULL, rate_r4 DOUBLE PRECISION NOT NULL, rate_r8 DOUBLE PRECISION NOT NULL, rate_r16 DOUBLE PRECISION NOT NULL, rate_r32 DOUBLE PRECISION NOT NULL, rate_r64 DOUBLE PRECISION NOT NULL, wallet_division SMALLINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ECB7831DB26853692534008B ON moon_tax_refinery_settings (corporation_id, structure_id)');
        $this->addSql('CREATE TABLE moon_tax_statements (id UUID NOT NULL, corporation_id BIGINT NOT NULL, structure_id BIGINT NOT NULL, character_id BIGINT NOT NULL, period_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, period_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reference VARCHAR(24) NOT NULL, lines JSON NOT NULL, tax DOUBLE PRECISION DEFAULT NULL, unknown_type_ids JSON NOT NULL, recorded_corporation_id BIGINT DEFAULT NULL, outside_alliance BOOLEAN DEFAULT NULL, marked_paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, acted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, acted_by_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_492ADE44AEA34913 ON moon_tax_statements (reference)');
        $this->addSql('CREATE INDEX IDX_492ADE4437CA1F1D ON moon_tax_statements (acted_by_user_id)');
        $this->addSql('CREATE INDEX IDX_492ADE44B2685369AED1DDEA ON moon_tax_statements (corporation_id, period_start)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_492ADE44B26853692534008BAED1DDEA1136BE75 ON moon_tax_statements (corporation_id, structure_id, period_start, character_id)');
        $this->addSql('CREATE TABLE moon_tax_sync_states (id UUID NOT NULL, corporation_id BIGINT NOT NULL, observers_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, wallet_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, observers_failure VARCHAR(32) DEFAULT NULL, wallet_failure VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1A7B9E4BB2685369 ON moon_tax_sync_states (corporation_id)');
        $this->addSql('ALTER TABLE corporation_wallet_donations ADD CONSTRAINT FK_347FFEDAE7B0B596 FOREIGN KEY (attributed_by_user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moon_tax_statements ADD CONSTRAINT FK_492ADE4437CA1F1D FOREIGN KEY (acted_by_user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE corporation_wallet_donations DROP CONSTRAINT FK_347FFEDAE7B0B596');
        $this->addSql('ALTER TABLE moon_tax_statements DROP CONSTRAINT FK_492ADE4437CA1F1D');
        $this->addSql('DROP TABLE corporation_wallet_donations');
        $this->addSql('DROP TABLE moon_observer_records');
        $this->addSql('DROP TABLE moon_tax_refinery_settings');
        $this->addSql('DROP TABLE moon_tax_statements');
        $this->addSql('DROP TABLE moon_tax_sync_states');
    }
}
