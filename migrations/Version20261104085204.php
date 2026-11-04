<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104085204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link mining sites to an operation and record the author of each site scan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_site_scans ADD author_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_site_scans ADD CONSTRAINT FK_8FA59BA8F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8FA59BA8F675F31B ON mining_site_scans (author_id)');
        $this->addSql('UPDATE mining_site_scans SET author_id = (SELECT owner_id FROM mining_sites WHERE mining_sites.id = mining_site_scans.site_id)');
        $this->addSql('ALTER TABLE mining_sites ADD operation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_sites ADD CONSTRAINT FK_D5D527B644AC3583 FOREIGN KEY (operation_id) REFERENCES mining_operations (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D5D527B644AC3583 ON mining_sites (operation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_site_scans DROP CONSTRAINT FK_8FA59BA8F675F31B');
        $this->addSql('DROP INDEX IDX_8FA59BA8F675F31B');
        $this->addSql('ALTER TABLE mining_site_scans DROP author_id');
        $this->addSql('ALTER TABLE mining_sites DROP CONSTRAINT FK_D5D527B644AC3583');
        $this->addSql('DROP INDEX IDX_D5D527B644AC3583');
        $this->addSql('ALTER TABLE mining_sites DROP operation_id');
    }
}
