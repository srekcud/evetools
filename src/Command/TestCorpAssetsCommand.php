<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CharacterRepository;
use App\Service\ESI\EsiClient;
use App\Service\Sync\AssetsSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:corp-assets',
    description: 'Test ESI corporation assets access',
)]
class TestCorpAssetsCommand extends Command
{
    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly EsiClient $esiClient,
        private readonly AssetsSyncService $assetsSyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sync', 's', InputOption::VALUE_NONE, 'Actually sync corporation assets');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Find all characters with tokens
        $characters = $this->characterRepository->findAll();
        $charactersWithTokens = [];
        foreach ($characters as $char) {
            if ($char->getEveToken()) {
                $charactersWithTokens[] = $char;
            }
        }

        if (empty($charactersWithTokens)) {
            $io->error('No characters with tokens found');
            return Command::FAILURE;
        }

        // Test each character
        foreach ($charactersWithTokens as $testChar) {
            $token = $testChar->getEveToken();
            $corporationId = $testChar->getCorporationId();

            $io->title("Testing: {$testChar->getName()}" . ($testChar->isMain() ? ' (MAIN)' : ' (ALT)'));

            $io->listing([
                "Character: {$testChar->getName()}",
                "Corporation: {$testChar->getCorporationName()} (ID: {$corporationId})",
            ]);

            // Check relevant scopes
            $io->section('Relevant ESI Scopes');
            $scopes = $token->getScopes();
            $relevantScopes = [];
            foreach ($scopes as $scope) {
                if (str_contains($scope, 'corp') || str_contains($scope, 'asset') || str_contains($scope, 'role')) {
                    $relevantScopes[] = $scope;
                }
            }
            $io->listing($relevantScopes);

            // Test character roles endpoint
            $io->section('Character Corporation Roles');
            try {
                $characterId = $testChar->getEveCharacterId();
                $roles = $this->esiClient->get("/characters/{$characterId}/roles/", $token);

                $io->text('Roles:');
                if (!empty($roles['roles'])) {
                    $io->listing($roles['roles']);
                } else {
                    $io->warning('No roles found');
                }

                $io->text('Roles at HQ:');
                if (!empty($roles['roles_at_hq'])) {
                    $io->listing($roles['roles_at_hq']);
                } else {
                    $io->text('  (none)');
                }

                $io->text('Roles at Base:');
                if (!empty($roles['roles_at_base'])) {
                    $io->listing($roles['roles_at_base']);
                } else {
                    $io->text('  (none)');
                }

                $io->text('Roles at Other:');
                if (!empty($roles['roles_at_other'])) {
                    $io->listing($roles['roles_at_other']);
                } else {
                    $io->text('  (none)');
                }
            } catch (\Throwable $e) {
                $io->error("Failed to get roles: {$e->getMessage()}");
            }

            // Test corporation assets endpoint
            $io->section('Corporation Assets Test');
            try {
                $io->text("Fetching /corporations/{$corporationId}/assets/ ...");
                $assets = $this->esiClient->getPaginated("/corporations/{$corporationId}/assets/", $token);

                $io->success("Successfully retrieved " . count($assets) . " corporation assets!");

                // Show sample
                if (!empty($assets)) {
                    $io->text('Sample assets (first 5):');
                    $sample = array_slice($assets, 0, 5);
                    foreach ($sample as $asset) {
                        $io->text("  - Item ID: {$asset['item_id']}, Type ID: {$asset['type_id']}, Location Flag: " . ($asset['location_flag'] ?? 'N/A'));
                    }
                }
            } catch (\Throwable $e) {
                $io->error("Failed to get corporation assets: {$e->getMessage()}");
                $io->note('This usually means the character lacks the Director role or esi-assets.read_corporation_assets.v1 scope');
            }

            // Test corporation divisions
            $io->section('Corporation Divisions Test');
            try {
                $io->text("Fetching /corporations/{$corporationId}/divisions/ ...");
                $divisions = $this->esiClient->get("/corporations/{$corporationId}/divisions/", $token);

                $io->success("Successfully retrieved divisions!");

                if (!empty($divisions['hangar'])) {
                    $io->text('Hangar Divisions:');
                    foreach ($divisions['hangar'] as $div) {
                        $io->text("  - Division {$div['division']}: " . ($div['name'] ?? 'Unnamed'));
                    }
                }

                if (!empty($divisions['wallet'])) {
                    $io->text('Wallet Divisions:');
                    foreach ($divisions['wallet'] as $div) {
                        $io->text("  - Division {$div['division']}: " . ($div['name'] ?? 'Unnamed'));
                    }
                }
            } catch (\Throwable $e) {
                $io->error("Failed to get divisions: {$e->getMessage()}");
            }

            $io->newLine(2);
        }

        // If --sync option is provided, try to sync corp assets using any character with access
        if ($input->getOption('sync')) {
            $io->title('Corporation Assets Sync (using any character with access)');

            // Get the first character's corporation ID
            $firstChar = $charactersWithTokens[0];
            $corporationId = $firstChar->getCorporationId();

            $io->text("Corporation ID: {$corporationId}");
            $io->text("Checking for character with corp assets access...");

            $canSync = $this->assetsSyncService->canSyncCorporationAssets($corporationId);
            $accessChar = $this->assetsSyncService->getCorpAssetsCharacter($corporationId);

            if ($accessChar) {
                $io->success("Found character with access: {$accessChar->getName()}");
                $io->text("Attempting to sync corporation assets...");

                try {
                    $result = $this->assetsSyncService->syncCorporationAssetsForCorp($corporationId);
                    if ($result) {
                        $io->success("Corporation assets synced successfully!");
                    } else {
                        $io->error("Sync returned false - character may lack Director role in-game");
                    }
                } catch (\Throwable $e) {
                    $io->error("Sync failed: {$e->getMessage()}");
                }
            } else {
                $io->warning("No character with corporation assets access found. Need a character with esi-assets.read_corporation_assets.v1 scope.");
            }
        }

        return Command::SUCCESS;
    }
}
