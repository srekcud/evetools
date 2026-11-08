<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

use App\Entity\IndustryProjectCourierContract;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\IndustryProjectCourierContractRepository;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CourierFailureNotifier
{
    private const string TITLE_TEMPLATE = 'Courier contract failed: %s';
    private const string MESSAGE_TEMPLATE = 'The courier contract #%d linked to the project "%s" has failed: the materials bought for the project are not delivered.';
    private const string ROUTE_TEMPLATE = '/industry/projects/%s';
    private const string UNTITLED_PROJECT = 'Untitled project';

    public function __construct(
        private IndustryProjectCourierContractRepository $linkRepository,
        private CourierContractReader $contractReader,
        private NotificationDispatcher $dispatcher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function notifyFailedContracts(): int
    {
        $alerts = 0;
        foreach ($this->pendingLinksByOwner() as $ownerLinks) {
            $contractsById = $this->contractsById($ownerLinks['owner'], $ownerLinks['links']);
            foreach ($ownerLinks['links'] as $link) {
                if (CourierContractState::Failed === ($contractsById[$link->getContractId()]->state ?? null)) {
                    $this->notify($link);
                    ++$alerts;
                }
            }
        }

        return $alerts;
    }

    /**
     * @return array<int, array{owner: User, links: list<IndustryProjectCourierContract>}>
     */
    private function pendingLinksByOwner(): array
    {
        $ownerLinks = [];
        foreach ($this->linkRepository->findPendingFailureCheck() as $link) {
            if (null !== $link->getFailureNotifiedAt()) {
                continue;
            }

            $owner = $link->getProject()->getOwner();
            $ownerKey = spl_object_id($owner);
            $ownerLinks[$ownerKey] ??= ['owner' => $owner, 'links' => []];
            $ownerLinks[$ownerKey]['links'][] = $link;
        }

        return $ownerLinks;
    }

    /**
     * @param list<IndustryProjectCourierContract> $links
     *
     * @return array<int, CourierContract>
     */
    private function contractsById(User $owner, array $links): array
    {
        $earliestProjectCreation = new \DateTimeImmutable();
        foreach ($links as $link) {
            $earliestProjectCreation = min($earliestProjectCreation, $link->getProject()->getCreatedAt());
        }

        $contractsById = [];
        foreach ($this->contractReader->read($owner, $earliestProjectCreation)->contracts as $contract) {
            $contractsById[$contract->contractId] = $contract;
        }

        return $contractsById;
    }

    private function notify(IndustryProjectCourierContract $link): void
    {
        $project = $link->getProject();
        $projectName = $project->getName() ?? self::UNTITLED_PROJECT;
        $projectId = $project->getId()->toRfc4122();

        $this->dispatcher->dispatch(
            $project->getOwner(),
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_WARNING,
            sprintf(self::TITLE_TEMPLATE, $projectName),
            sprintf(self::MESSAGE_TEMPLATE, $link->getContractId(), $projectName),
            ['projectId' => $projectId, 'contractId' => $link->getContractId()],
            sprintf(self::ROUTE_TEMPLATE, $projectId),
        );

        $link->markFailureNotified(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
