<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CharacterRepository;
use App\Service\ESI\EsiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:search-structures',
    description: 'Search for structures by name',
)]
class SearchStructuresCommand extends Command
{
    private const KEEPSTAR_TYPE_ID = 35834;
    private const FORTIZAR_TYPE_ID = 35833;
    private const AZBEL_TYPE_ID = 35825;
    private const TATARA_TYPE_ID = 35836;
    private const SOTIYO_TYPE_ID = 35827;

    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly EsiClient $esiClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('character_name', InputArgument::REQUIRED, 'Character name')
            ->addArgument('search', InputArgument::REQUIRED, 'Search term');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $characterName = $input->getArgument('character_name');
        $search = $input->getArgument('search');

        $character = $this->characterRepository->findOneBy(['name' => $characterName]);
        if (!$character) {
            $io->error("Character '{$characterName}' not found");
            return Command::FAILURE;
        }

        $token = $character->getEveToken();
        if (!$token) {
            $io->error('Character has no token');
            return Command::FAILURE;
        }

        $characterId = $character->getEveCharacterId();

        $io->info("Searching for structures matching: {$search}");

        try {
            $result = $this->esiClient->get(
                "/characters/{$characterId}/search/?categories=structure&search=" . urlencode($search),
                $token
            );

            $structureIds = $result['structure'] ?? [];
            $io->info("Found " . count($structureIds) . " structures");

            $rows = [];
            foreach ($structureIds as $structureId) {
                try {
                    $info = $this->esiClient->get("/universe/structures/{$structureId}/", $token);
                    $typeName = $this->getTypeName($info['type_id'] ?? 0);
                    $rows[] = [
                        $structureId,
                        $info['name'] ?? 'Unknown',
                        $typeName,
                        $info['solar_system_id'] ?? '-',
                    ];
                } catch (\Exception $e) {
                    $rows[] = [$structureId, 'Error: ' . $e->getMessage(), '-', '-'];
                }
            }

            $io->table(['Structure ID', 'Name', 'Type', 'System ID'], $rows);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Search failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function getTypeName(int $typeId): string
    {
        return match ($typeId) {
            self::KEEPSTAR_TYPE_ID => 'Keepstar',
            self::FORTIZAR_TYPE_ID => 'Fortizar',
            self::AZBEL_TYPE_ID => 'Azbel',
            self::TATARA_TYPE_ID => 'Tatara',
            self::SOTIYO_TYPE_ID => 'Sotiyo',
            default => "Type {$typeId}",
        };
    }
}
