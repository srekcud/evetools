<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CharacterRepository;
use App\Service\Sync\AnsiblexSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ansiblex:sync',
    description: 'Sync Ansiblex jump gates from a character\'s corporation',
)]
class AnsiblexSyncCommand extends Command
{
    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly AnsiblexSyncService $ansiblexSyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('character', InputArgument::REQUIRED, 'Character name or UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $characterArg = $input->getArgument('character');
        $character = null;

        // Try to find by UUID first if it looks like a UUID
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $characterArg)) {
            $character = $this->characterRepository->find($characterArg);
        }

        // Try by exact name
        if (!$character) {
            $character = $this->characterRepository->findOneBy(['name' => $characterArg]);
        }

        // Try case-insensitive search
        if (!$character) {
            $characters = $this->characterRepository->createQueryBuilder('c')
                ->where('LOWER(c.name) = LOWER(:name)')
                ->setParameter('name', $characterArg)
                ->getQuery()
                ->getResult();

            if (count($characters) === 1) {
                $character = $characters[0];
            }
        }

        if (!$character) {
            $io->error("Character not found: {$characterArg}");
            return Command::FAILURE;
        }

        $io->title('Ansiblex Jump Gate Sync');

        $io->table(['Property', 'Value'], [
            ['Character', $character->getName()],
            ['Corporation ID', $character->getCorporationId()],
            ['Corporation', $character->getCorporationName()],
            ['Alliance ID', $character->getAllianceId() ?? 'N/A'],
            ['Alliance', $character->getAllianceName() ?? 'N/A'],
        ]);

        if (!$this->ansiblexSyncService->canSync($character)) {
            $token = $character->getEveToken();
            $scopes = $token ? $token->getScopes() : [];

            $io->error('Cannot sync: missing token or required scope (esi-corporations.read_structures.v1)');

            if ($token) {
                $io->section('Available scopes:');
                if (empty($scopes) || (count($scopes) === 1 && $scopes[0] === '')) {
                    $io->warning('No scopes found. User needs to re-authenticate with required scopes.');
                } else {
                    $io->listing($scopes);

                    $hasRequiredScope = in_array('esi-corporations.read_structures.v1', $scopes, true);
                    if (!$hasRequiredScope) {
                        $io->warning('Missing required scope: esi-corporations.read_structures.v1');
                    }
                }
            } else {
                $io->warning('No EVE token found for this character.');
            }

            return Command::FAILURE;
        }

        $io->section('Starting sync...');

        try {
            $stats = $this->ansiblexSyncService->syncFromCharacter($character);

            if ($stats['added'] === 0 && $stats['updated'] === 0 && $stats['deactivated'] === 0) {
                $io->warning([
                    'Sync completed but no structures found.',
                    '',
                    'This could mean:',
                    '1. The corporation has no Ansiblex jump gates',
                    '2. The character lacks Director or Station_Manager role in-game',
                    '',
                    'Note: The esi-corporations.read_structures.v1 scope grants API access,',
                    'but the character ALSO needs an in-game role to view structures.',
                ]);
            } else {
                $io->success('Sync completed!');
            }

            $io->table(['Metric', 'Count'], [
                ['Added', $stats['added']],
                ['Updated', $stats['updated']],
                ['Deactivated', $stats['deactivated']],
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error([
                'Sync failed!',
                $e->getMessage(),
            ]);

            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
