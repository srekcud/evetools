<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-vapid-keys',
    description: 'Generate VAPID key pair for Web Push notifications'
)]
class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Generating VAPID Keys');

        $keys = VAPID::createVapidKeys();

        $io->section('Add these to your .env file:');
        $io->text([
            sprintf('VAPID_PUBLIC_KEY=%s', $keys['publicKey']),
            sprintf('VAPID_PRIVATE_KEY=%s', $keys['privateKey']),
            'VAPID_SUBJECT=mailto:your-email@example.com',
        ]);

        $io->note('The public key is also needed on the frontend for the Push API registration.');

        return Command::SUCCESS;
    }
}
