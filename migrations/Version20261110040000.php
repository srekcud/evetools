<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110040000 extends AbstractMigration
{
    private const array RENAMED_RIGS = [
        'Standup M-Set Laboratory Optimization I' => 'Standup M-Set Blueprint Copy Accelerator I',
        'Standup M-Set Laboratory Optimization II' => 'Standup M-Set Blueprint Copy Accelerator II',
        'Standup L-Set Laboratory Optimization I' => 'Standup L-Set Blueprint Copy Optimization I',
        'Standup L-Set Laboratory Optimization II' => 'Standup L-Set Blueprint Copy Optimization II',
    ];

    public function getDescription(): string
    {
        return 'Rename the laboratory rigs missing from the SDE in the fitted rigs of production structures to the copy rig of the same size and time bonus';
    }

    public function up(Schema $schema): void
    {
        $renameCases = '';
        foreach (self::RENAMED_RIGS as $oldName => $newName) {
            $renameCases .= \sprintf(" WHEN '%s' THEN '%s'", $oldName, $newName);
        }
        $oldNames = "'".implode("', '", array_keys(self::RENAMED_RIGS))."'";

        $this->addSql(<<<SQL
            UPDATE industry_structure_configs SET rigs = (
                SELECT COALESCE(json_agg(renamed.rig ORDER BY renamed.first_position), '[]'::json)
                FROM (
                    SELECT mapped.rig, MIN(mapped.position) AS first_position
                    FROM (
                        SELECT CASE fitted.rig{$renameCases} ELSE fitted.rig END AS rig, fitted.position
                        FROM json_array_elements_text(industry_structure_configs.rigs) WITH ORDINALITY AS fitted(rig, position)
                    ) mapped
                    GROUP BY mapped.rig
                ) renamed
            )
            WHERE EXISTS (
                SELECT 1 FROM json_array_elements_text(industry_structure_configs.rigs) AS stale(rig) WHERE stale.rig IN ({$oldNames})
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The old rig names do not exist in the SDE: they are not restored.');
    }
}
