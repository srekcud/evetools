<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EveTokenRepository;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fix-token-scopes',
    description: 'Fix tokens with empty scopes by refreshing them',
)]
class FixTokenScopesCommand extends Command
{
    public function __construct(
        private readonly EveTokenRepository $tokenRepository,
        private readonly TokenManager $tokenManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tokens = $this->tokenRepository->findAll();
        $fixed = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            $character = $token->getCharacter();
            $name = $character?->getName() ?? 'Unknown';

            // Check if scopes are empty or just contain empty string
            $scopes = $token->getScopes();
            if (!empty($scopes) && $scopes !== ['']) {
                $io->text("Skipping {$name} - already has " . count($scopes) . " scopes");
                continue;
            }

            $io->text("Fixing {$name}...");

            try {
                // Try to extract scopes from existing JWT first
                $scopes = $this->tokenManager->extractScopesFromJwt($token->getAccessToken());

                if (!empty($scopes)) {
                    $token->setScopes($scopes);
                    $this->entityManager->flush();
                    $io->success("Fixed {$name} from existing JWT - " . count($scopes) . " scopes");
                    $fixed++;
                    continue;
                }

                // If that fails, try refreshing the token
                $this->tokenManager->refreshAccessToken($token);
                $newScopes = $token->getScopes();

                if (!empty($newScopes) && $newScopes !== ['']) {
                    $io->success("Fixed {$name} via refresh - " . count($newScopes) . " scopes");
                    $fixed++;
                } else {
                    $io->warning("Refreshed {$name} but still no scopes");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $io->error("Failed to fix {$name}: " . $e->getMessage());
                $failed++;
            }
        }

        $io->info("Fixed: {$fixed}, Failed: {$failed}");

        return $fixed > 0 || $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
