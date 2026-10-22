<?php

declare(strict_types=1);

namespace App\Command;

use App\Constant\StructureRigTargets;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-rig-definitions',
    description: 'Seed industry rig definitions (ME/TE bonuses) from SDE'
)]
class SeedRigDefinitionsCommand extends Command
{
    // Reaction rig attributes
    private const ATTR_REACTION_MATERIAL_BONUS = 2714;
    private const ATTR_REACTION_TIME_BONUS = 2713;

    // Manufacturing rig attributes
    private const ATTR_MANUFACTURING_MATERIAL_BONUS = 2594;
    private const ATTR_MANUFACTURING_TIME_BONUS = 2593;

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding Industry Rig Definitions from SDE');

        // Find all Standup rigs with their bonuses
        // Reaction rigs use attributes 2714/2713, manufacturing rigs use 2594/2593
        $sql = "
            SELECT
                t.type_id,
                t.type_name,
                COALESCE(
                    NULLIF(me_react.value_float, 0),
                    NULLIF(me_manuf.value_float, 0),
                    0
                ) as material_bonus,
                COALESCE(
                    NULLIF(te_react.value_float, 0),
                    NULLIF(te_manuf.value_float, 0),
                    0
                ) as time_bonus
            FROM sde_inv_types t
            LEFT JOIN sde_dgm_type_attributes me_react
                ON me_react.type_id = t.type_id AND me_react.attribute_id = :attr_me_react
            LEFT JOIN sde_dgm_type_attributes te_react
                ON te_react.type_id = t.type_id AND te_react.attribute_id = :attr_te_react
            LEFT JOIN sde_dgm_type_attributes me_manuf
                ON me_manuf.type_id = t.type_id AND me_manuf.attribute_id = :attr_me_manuf
            LEFT JOIN sde_dgm_type_attributes te_manuf
                ON te_manuf.type_id = t.type_id AND te_manuf.attribute_id = :attr_te_manuf
            WHERE t.type_name LIKE 'Standup %Set%'
              AND t.type_name NOT LIKE '%Blueprint%'
              AND (
                  me_react.value_float IS NOT NULL
                  OR te_react.value_float IS NOT NULL
                  OR me_manuf.value_float IS NOT NULL
                  OR te_manuf.value_float IS NOT NULL
              )
            ORDER BY t.type_name
        ";

        $rigs = $this->connection->fetchAllAssociative($sql, [
            'attr_me_react' => self::ATTR_REACTION_MATERIAL_BONUS,
            'attr_te_react' => self::ATTR_REACTION_TIME_BONUS,
            'attr_me_manuf' => self::ATTR_MANUFACTURING_MATERIAL_BONUS,
            'attr_te_manuf' => self::ATTR_MANUFACTURING_TIME_BONUS,
        ]);

        $io->info(sprintf('Found %d rigs with bonuses in SDE', count($rigs)));

        // Clear existing definitions
        $this->connection->executeStatement('DELETE FROM industry_rig_definitions');

        $inserted = 0;
        $skipped = 0;

        foreach ($rigs as $rig) {
            $rigName = $rig['type_name'];
            $materialBonus = abs((float) $rig['material_bonus']);
            $timeBonus = abs((float) $rig['time_bonus']);

            // Determine target categories based on rig name
            $targetCategories = StructureRigTargets::categoriesOf($rigName);
            $isReaction = $this->isReactionRig($rigName);

            if (empty($targetCategories)) {
                $io->note("Skipping {$rigName} - no matching category");
                $skipped++;
                continue;
            }

            $this->connection->insert('industry_rig_definitions', [
                'rig_name' => $rigName,
                'rig_type_id' => $rig['type_id'],
                'material_bonus' => $materialBonus,
                'time_bonus' => $timeBonus,
                'target_categories' => json_encode($targetCategories),
                'is_reaction' => $isReaction ? 'true' : 'false',
            ]);

            $inserted++;
            $io->writeln(sprintf(
                '  [%s] ME: %.1f%%, TE: %.1f%% → %s',
                $rigName,
                $materialBonus,
                $timeBonus,
                implode(', ', $targetCategories)
            ));
        }

        $io->success(sprintf('Inserted %d rig definitions (%d skipped)', $inserted, $skipped));

        return Command::SUCCESS;
    }

    private function isReactionRig(string $rigName): bool
    {
        return str_contains($rigName, 'Reactor');
    }
}
