<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SyncPublicContracts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:sync-public-contracts',
    description: 'Dispatch public contracts sync (The Forge mono-item contract prices)',
)]
class SyncPublicContractsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Dispatching public contracts sync...');
        $this->messageBus->dispatch(new SyncPublicContracts());
        $io->success('Message dispatched. The worker will process it asynchronously.');

        return Command::SUCCESS;
    }
}
