<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Enum\ParticipantStatus;
use App\Service\Mercure\MercurePublisherService;

final class BomLineMarkNotifier
{
    private const string SYNC_TYPE = 'industry-project';

    public static function notifyLineChanged(MercurePublisherService $mercurePublisher, IndustryProject $project, IndustryProjectBomLine $line): void
    {
        $data = ['projectId' => $project->getId()->toRfc4122(), 'lineId' => $line->getId()->toRfc4122()];

        foreach ($project->getMembers() as $participant) {
            $user = $participant->getUser();
            if (ParticipantStatus::Accepted !== $participant->getStatus() || null === $user) {
                continue;
            }

            $userId = $user->getId() ?? throw new \LogicException('A persisted participant user has no id.');
            $mercurePublisher->syncCompleted($userId->toRfc4122(), self::SYNC_TYPE, null, $data);
        }
    }
}
