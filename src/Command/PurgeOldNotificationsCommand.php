<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\NotificationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-notifications',
    description: 'Delete notifications older than 7 days'
)]
class PurgeOldNotificationsCommand extends Command
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $threshold = new \DateTimeImmutable('-7 days');
        $deleted = $this->notificationRepository->deleteOlderThan($threshold);

        $io->success(sprintf('Deleted %d notifications older than %s', $deleted, $threshold->format('Y-m-d H:i:s')));

        return Command::SUCCESS;
    }
}
