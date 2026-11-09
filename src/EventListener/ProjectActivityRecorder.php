<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\IndustryProjectActivity;
use App\Enum\ProjectActivityKind;
use App\Event\GroupProjectEventPublished;
use App\Repository\IndustryProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class ProjectActivityRecorder
{
    public function __construct(
        private IndustryProjectRepository $projectRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GroupProjectEventPublished $event): void
    {
        $kind = ProjectActivityKind::tryFrom($event->action);
        if (null === $kind) {
            return;
        }

        try {
            $project = $this->projectRepository->find(Uuid::fromString($event->projectId));
            if (null === $project) {
                $this->logger->warning('Project activity not recorded: project not found', [
                    'projectId' => $event->projectId,
                    'action' => $event->action,
                ]);

                return;
            }

            $this->entityManager->persist(
                (new IndustryProjectActivity())
                    ->setProject($project)
                    ->setKind($kind)
                    ->setOccurredAt($event->occurredAt)
                    ->setData($event->data),
            );
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->warning('Project activity not recorded', [
                'projectId' => $event->projectId,
                'action' => $event->action,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
