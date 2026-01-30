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
    name: 'app:test:structure',
    description: 'Test ESI structure resolution with all available tokens',
)]
class TestStructureCommand extends Command
{
    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly EsiClient $esiClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('structureId', InputArgument::REQUIRED, 'Structure ID to test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $structureId = (int) $input->getArgument('structureId');

        $io->title("Testing structure resolution for ID: {$structureId}");

        $characters = $this->characterRepository->findAll();
        $tokensWithChars = [];

        foreach ($characters as $char) {
            $token = $char->getEveToken();
            if ($token !== null) {
                $tokensWithChars[] = ['token' => $token, 'name' => $char->getName()];
            }
        }

        $io->info("Found " . count($tokensWithChars) . " tokens to try");

        foreach ($tokensWithChars as $item) {
            $io->section("Trying with: {$item['name']}");

            try {
                $data = $this->esiClient->get("/universe/structures/{$structureId}/", $item['token']);
                $io->success('Structure resolved!');
                $io->listing([
                    "Name: " . ($data['name'] ?? 'N/A'),
                    "Solar System ID: " . ($data['solar_system_id'] ?? 'N/A'),
                    "Type ID: " . ($data['type_id'] ?? 'N/A'),
                ]);
                return Command::SUCCESS;
            } catch (\Throwable $e) {
                $io->warning("Failed: {$e->getMessage()}");
            }
        }

        $io->error("Could not resolve structure with any of the " . count($tokensWithChars) . " tokens");
        $io->note("This means none of your characters have docking access to this structure.");

        return Command::FAILURE;
    }
}
