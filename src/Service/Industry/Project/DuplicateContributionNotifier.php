<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\Notification;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Notification\NotificationDispatcher;

final readonly class DuplicateContributionNotifier
{
    private const string TITLE_TEMPLATE = 'Duplicate contribution: %s';
    private const string MESSAGE_TEMPLATE = 'In the project "%s", %s now has an ESI/wallet %s contribution on %s alongside the one they declared: review the declared contribution.';
    private const string ROUTE_TEMPLATE = '/industry/projects/%s';
    private const string UNTITLED_PROJECT = 'Untitled project';
    private const string UNKNOWN_PARTICIPANT = 'Unknown';

    public function __construct(
        private NotificationDispatcher $dispatcher,
    ) {
    }

    public function notifyReviewers(IndustryProjectContribution $derived): void
    {
        $project = $derived->getProject();
        $projectName = $project->getName() ?? self::UNTITLED_PROJECT;
        $projectId = $project->getId()->toRfc4122();
        $line = $derived->getBomItem();
        $lineName = $line?->getTypeName();
        $title = sprintf(self::TITLE_TEMPLATE, $projectName);
        $message = sprintf(
            self::MESSAGE_TEMPLATE,
            $projectName,
            PilotName::of($derived->getMember()->getUser()) ?? self::UNKNOWN_PARTICIPANT,
            $this->typeLabel($derived->getType()),
            $lineName,
        );
        $data = [
            'projectId' => $projectId,
            'contributionId' => $derived->getId()->toRfc4122(),
            'bomItemId' => $line?->getId()->toRfc4122(),
        ];

        foreach ($project->getMembers() as $member) {
            $reviewer = $member->getUser();
            if ($reviewer === null || !$this->isReviewer($member)) {
                continue;
            }

            $this->dispatcher->dispatch(
                $reviewer,
                Notification::CATEGORY_INDUSTRY,
                Notification::LEVEL_WARNING,
                $title,
                $message,
                $data,
                sprintf(self::ROUTE_TEMPLATE, $projectId),
            );
        }
    }

    private function isReviewer(IndustryProjectParticipant $member): bool
    {
        return ParticipantStatus::Accepted === $member->getStatus()
            && \in_array($member->getRole(), [ParticipantRole::Owner, ParticipantRole::Admin], true);
    }

    private function typeLabel(ContributionType $type): string
    {
        return str_replace('_', ' ', $type->value);
    }
}
