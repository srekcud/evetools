<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\Notification;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Notification\NotificationDispatcher;

final readonly class JoinRequestNotifier
{
    private const string REQUEST_TITLE_TEMPLATE = 'Join request: %s';
    private const string REQUEST_MESSAGE_TEMPLATE = '%s asks to join the project "%s": accept or decline the request in the Team tab.';
    private const string ACCEPTED_TITLE_TEMPLATE = 'Join request accepted: %s';
    private const string ACCEPTED_MESSAGE_TEMPLATE = 'Your request to join the project "%s" was accepted: you can now contribute to it.';
    private const string PROJECT_ROUTE_TEMPLATE = '/industry/projects/%s';
    private const string TEAM_TAB_QUERY = '?tab=members';
    private const string UNTITLED_PROJECT = 'Untitled project';
    private const string UNKNOWN_PARTICIPANT = 'Unknown';

    public function __construct(
        private NotificationDispatcher $dispatcher,
    ) {
    }

    public function notifyManagers(IndustryProjectParticipant $request): void
    {
        $project = $request->getProject();
        $projectName = $this->projectName($project);
        $projectId = $project->getId()->toRfc4122();
        $title = sprintf(self::REQUEST_TITLE_TEMPLATE, $projectName);
        $message = sprintf(
            self::REQUEST_MESSAGE_TEMPLATE,
            PilotName::of($request->getUser()) ?? self::UNKNOWN_PARTICIPANT,
            $projectName,
        );
        $data = ['projectId' => $projectId, 'memberId' => $request->getId()->toRfc4122()];
        $route = sprintf(self::PROJECT_ROUTE_TEMPLATE, $projectId) . self::TEAM_TAB_QUERY;

        foreach ($project->getMembers() as $member) {
            $manager = $member->getUser();
            if ($manager === null || !$this->isManager($member)) {
                continue;
            }

            $this->dispatcher->dispatch($manager, Notification::CATEGORY_INDUSTRY, Notification::LEVEL_INFO, $title, $message, $data, $route);
        }
    }

    public function notifyRequester(IndustryProjectParticipant $accepted): void
    {
        $requester = $accepted->getUser();
        if ($requester === null) {
            return;
        }

        $project = $accepted->getProject();
        $projectName = $this->projectName($project);
        $projectId = $project->getId()->toRfc4122();

        $this->dispatcher->dispatch(
            $requester,
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_SUCCESS,
            sprintf(self::ACCEPTED_TITLE_TEMPLATE, $projectName),
            sprintf(self::ACCEPTED_MESSAGE_TEMPLATE, $projectName),
            ['projectId' => $projectId],
            sprintf(self::PROJECT_ROUTE_TEMPLATE, $projectId),
        );
    }

    private function projectName(IndustryProject $project): string
    {
        return $project->getName() ?? self::UNTITLED_PROJECT;
    }

    private function isManager(IndustryProjectParticipant $member): bool
    {
        return ParticipantStatus::Accepted === $member->getStatus()
            && \in_array($member->getRole(), [ParticipantRole::Owner, ParticipantRole::Admin], true);
    }
}
