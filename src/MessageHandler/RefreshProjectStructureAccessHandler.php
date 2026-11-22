<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshProjectStructureAccess;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\StructureAccess\StructureAccessRefresher;
use App\Service\Mercure\MercurePublisherService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshProjectStructureAccessHandler
{
    private const string EVENT = 'structure_access_changed';

    public function __construct(
        private IndustryProjectRepository $projectRepository,
        private StructureAccessRefresher $refresher,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function __invoke(RefreshProjectStructureAccess $message): void
    {
        $project = $this->projectRepository->find($message->projectId);
        if (null === $project) {
            return;
        }

        $report = $this->refresher->refreshProject($project);

        $this->mercurePublisher->publishGroupProjectEvent($message->projectId, self::EVENT, $report->toArray());
    }
}
