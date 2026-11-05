<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105190000 extends AbstractMigration
{
    private const array COLUMNS_OF_THE_SOLO_PROJECT = [
        'product_type_id',
        'runs',
        'me_level',
        'te_level',
        'max_job_duration_days',
        'personal_use',
        'bpo_cost',
        'material_cost',
        'transport_cost',
        'tax_amount',
        'sell_price',
        'excluded_type_ids',
        'invention_materials',
        'estimated_job_cost',
        'estimated_material_cost',
        'estimated_sell_price',
        'estimated_sell_price_source',
        'estimated_tax_amount',
        'jobs_start_date',
    ];

    public function getDescription(): string
    {
        return 'Drop the industry project steps and the solo columns of the industry projects, add the private flag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE industry_step_job_matches');
        $this->addSql('DROP TABLE industry_step_purchases');
        $this->addSql('DROP TABLE industry_project_steps');
        $this->addSql('ALTER TABLE industry_projects ADD private BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE industry_projects '.implode(', ', array_map(
            static fn (string $column): string => 'DROP '.$column,
            self::COLUMNS_OF_THE_SOLO_PROJECT,
        )));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The industry project steps and the solo columns of the industry projects cannot be restored.');
    }
}
