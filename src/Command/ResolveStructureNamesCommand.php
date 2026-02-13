<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CachedAssetRepository;
use App\Repository\EveTokenRepository;
use App\Service\ESI\EsiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:resolve-structure-names',
    description: 'Resolve unresolved structure names in cached assets',
)]
class ResolveStructureNamesCommand extends Command
{
    public function __construct(
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly EveTokenRepository $eveTokenRepository,
        private readonly EsiClient $esiClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get all unresolved structure IDs
        $unresolvedStructures = $this->cachedAssetRepository->createQueryBuilder('a')
            ->select('DISTINCT a.locationId, a.locationName')
            ->where('a.locationName LIKE :pattern')
            ->setParameter('pattern', 'Structure #%')
            ->getQuery()
            ->getResult();

        if (empty($unresolvedStructures)) {
            $io->success('No unresolved structures found.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d unresolved structures.', count($unresolvedStructures)));

        // Get all available tokens
        $tokens = $this->eveTokenRepository->findAll();
        if (empty($tokens)) {
            $io->error('No tokens available to resolve structures.');
            return Command::FAILURE;
        }

        $io->info(sprintf('Using %d tokens to resolve structures.', count($tokens)));

        $resolved = 0;
        $failed = 0;

        $io->progressStart(count($unresolvedStructures));

        foreach ($unresolvedStructures as $structure) {
            $structureId = $structure['locationId'];
            $structureName = null;
            $solarSystemId = null;

            // Try each token until one succeeds
            foreach ($tokens as $token) {
                try {
                    $data = $this->esiClient->get("/universe/structures/{$structureId}/", $token);
                    $structureName = $data['name'] ?? null;
                    $solarSystemId = $data['solar_system_id'] ?? null;
                    break;
                } catch (\Throwable) {
                    continue;
                }
            }

            if ($structureName !== null) {
                // Update all cached assets with this location
                $this->cachedAssetRepository->createQueryBuilder('a')
                    ->update()
                    ->set('a.locationName', ':name')
                    ->set('a.solarSystemId', ':solarSystemId')
                    ->where('a.locationId = :locationId')
                    ->setParameter('name', $structureName)
                    ->setParameter('solarSystemId', $solarSystemId)
                    ->setParameter('locationId', $structureId)
                    ->getQuery()
                    ->execute();

                $resolved++;
                $io->progressAdvance();
            } else {
                $failed++;
                $io->progressAdvance();
            }
        }

        $io->progressFinish();

        $io->success(sprintf(
            'Resolved %d structures, %d could not be resolved.',
            $resolved,
            $failed
        ));

        return Command::SUCCESS;
    }
}
