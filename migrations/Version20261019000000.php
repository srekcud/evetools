<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000000 extends AbstractMigration
{
    private const string OUTPUT_PER_RUN_BY_PRODUCTION_JOB_LINE = <<<'SQL'
        SELECT bom_item.id AS bom_item_id, MIN(product.quantity) AS output_per_run
        FROM group_industry_bom_items bom_item
        INNER JOIN sde_industry_activity_products product
            ON product.product_type_id = bom_item.type_id
            AND product.activity_id = CASE bom_item.activity_type WHEN 'manufacturing' THEN 1 WHEN 'reaction' THEN 11 END
        WHERE bom_item.is_job = true
            AND bom_item.activity_type IN ('manufacturing', 'reaction')
        GROUP BY bom_item.id
        HAVING COUNT(DISTINCT product.quantity) = 1
        SQL;

    public function getDescription(): string
    {
        return 'Convert the quantities of group industry production job lines and their contributions from runs to units';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            'UPDATE group_industry_contributions contribution SET quantity = contribution.quantity * job_line.output_per_run FROM (%s) job_line WHERE contribution.bom_item_id = job_line.bom_item_id',
            self::OUTPUT_PER_RUN_BY_PRODUCTION_JOB_LINE,
        ));
        $this->addSql(sprintf(
            'UPDATE group_industry_bom_items bom_item SET required_quantity = bom_item.required_quantity * job_line.output_per_run, fulfilled_quantity = bom_item.fulfilled_quantity * job_line.output_per_run FROM (%s) job_line WHERE bom_item.id = job_line.bom_item_id',
            self::OUTPUT_PER_RUN_BY_PRODUCTION_JOB_LINE,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Quantities converted to units cannot be safely converted back to runs');
    }
}
