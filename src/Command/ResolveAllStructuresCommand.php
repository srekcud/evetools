<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CachedStructure;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\EveTokenRepository;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:structures:resolve-all',
    description: 'Resolve all unresolved structures and mark inaccessible ones as "Structure hostile"',
)]
class ResolveAllStructuresCommand extends Command
{
    public function __construct(
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly EveTokenRepository $eveTokenRepository,
        private readonly EsiClient $esiClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done, do not save')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-resolve already cached structures');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $io->title('Resolving all structures');

        // Get all tokens
        $tokens = $this->eveTokenRepository->findAll();
        if (empty($tokens)) {
            $io->error('No tokens available');
            return Command::FAILURE;
        }

        $io->info(sprintf('Found %d tokens to use for resolution', count($tokens)));

        // Get all unique structure IDs from cached assets
        $structureIds = $this->getUnresolvedStructureIds($force);

        if (empty($structureIds)) {
            $io->success('No unresolved structures found');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d structures to resolve', count($structureIds)));

        $resolved = 0;
        $hostile = 0;
        $failed = 0;
        $unresolvedIds = [];

        // Pass 1: try with single token (fast, avoids burning rate limit)
        $io->section('Pass 1: trying with primary token');
        $io->progressStart(count($structureIds));

        foreach ($structureIds as $structureId) {
            $result = $this->tryResolveStructure($structureId, $tokens, singleTokenOnly: true);

            if ($result === null) {
                $unresolvedIds[] = $structureId;
                $io->progressAdvance();
                continue;
            }

            if (!$dryRun) {
                $this->saveStructure($structureId, $result['name'], $result['solar_system_id'] ?? null);
            }
            $resolved++;
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->info(sprintf('Pass 1: %d resolved, %d remaining', $resolved, count($unresolvedIds)));

        // Flush pass 1 results
        if (!$dryRun && $resolved > 0) {
            $this->entityManager->flush();
        }

        // Pass 2: try remaining with all tokens
        if (!empty($unresolvedIds)) {
            $io->section('Pass 2: trying remaining structures with all tokens');
            $io->progressStart(count($unresolvedIds));

            foreach ($unresolvedIds as $structureId) {
                $result = $this->tryResolveStructure($structureId, $tokens, singleTokenOnly: false);

                if ($result === null) {
                    if (!$dryRun) {
                        $this->saveStructure($structureId, 'Structure hostile', null);
                    }
                    $hostile++;
                    $io->progressAdvance();
                    continue;
                }

                if (!$dryRun) {
                    $this->saveStructure($structureId, $result['name'], $result['solar_system_id'] ?? null);
                }
                $resolved++;
                $io->progressAdvance();
            }

            $io->progressFinish();
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->newLine();
        $io->table(
            ['Status', 'Count'],
            [
                ['Resolved', $resolved],
                ['Hostile (inaccessible)', $hostile],
                ['Failed', $failed],
                ['Total', count($structureIds)],
            ]
        );

        if ($dryRun) {
            $io->warning('Dry run - no changes were saved');
        } else {
            $io->success('Structures resolved and saved to database');
        }

        return Command::SUCCESS;
    }

    /**
     * @return int[]
     */
    private function getUnresolvedStructureIds(bool $force): array
    {
        $conn = $this->entityManager->getConnection();

        // Get all structure location IDs from cached assets (IDs >= 1000000000000 are structures)
        $sql = 'SELECT DISTINCT location_id FROM cached_assets WHERE location_id >= 1000000000000';
        $allStructureIds = $conn->fetchFirstColumn($sql);

        if ($force) {
            return array_map('intval', $allStructureIds);
        }

        // Get already cached structure IDs
        $cachedIds = [];
        $cached = $this->cachedStructureRepository->findAll();
        foreach ($cached as $structure) {
            $cachedIds[$structure->getStructureId()] = true;
        }

        // Return only unresolved ones
        $unresolved = [];
        foreach ($allStructureIds as $id) {
            $id = (int) $id;
            if (!isset($cachedIds[$id])) {
                $unresolved[] = $id;
            }
        }

        return $unresolved;
    }

    /**
     * @param array<\App\Entity\EveToken> $tokens
     * @return array{name: string, solar_system_id: ?int}|null
     */
    /**
     * Try to resolve a structure. On first pass, only use the first token.
     * On second pass (retry), try all tokens.
     */
    private function tryResolveStructure(int $structureId, array $tokens, bool $singleTokenOnly = false): ?array
    {
        $tokensToTry = $singleTokenOnly ? [$tokens[0]] : $tokens;

        foreach ($tokensToTry as $token) {
            try {
                $data = $this->esiClient->get("/universe/structures/{$structureId}/", $token);
                return [
                    'name' => $data['name'],
                    'solar_system_id' => $data['solar_system_id'] ?? null,
                ];
            } catch (\Throwable) {
                usleep(500_000);
                continue;
            }
        }

        return null;
    }

    private function saveStructure(int $structureId, string $name, ?int $solarSystemId): void
    {
        $existing = $this->cachedStructureRepository->findByStructureId($structureId);

        if ($existing !== null) {
            $existing->setName($name);
            $existing->setSolarSystemId($solarSystemId);
            $existing->setResolvedAt(new \DateTimeImmutable());
            return;
        }

        $structure = new CachedStructure();
        $structure->setStructureId($structureId);
        $structure->setName($name);
        $structure->setSolarSystemId($solarSystemId);

        $this->entityManager->persist($structure);
    }
}
