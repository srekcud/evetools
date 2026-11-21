<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\JoinRequestNotifier;
use App\Service\Notification\NotificationDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JoinRequestNotifier::class)]
#[Group('issue-1219')]
final class JoinRequestNotifierTest extends TestCase
{
    private const string PROJECT_NAME = 'Sabre batch';
    private const string REQUESTER_NAME = 'Vex Tanner';

    private IndustryProject $project;
    private User $owner;
    private User $admin;
    private User $member;
    private User $requester;
    private IndustryProjectParticipant $request;
    private JoinRequestNotifier $notifier;

    /** @var list<array{User, string, string, string, string, ?array<string, mixed>, ?string}> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->owner = $this->createStub(User::class);
        $this->admin = $this->createStub(User::class);
        $this->member = $this->createStub(User::class);
        $this->requester = $this->createStub(User::class);
        $this->requester->method('getMainCharacter')->willReturn((new Character())->setName(self::REQUESTER_NAME));

        $this->project = (new IndustryProject())->setStatus(ProjectStatus::Published)->setName(self::PROJECT_NAME);
        $this->project->setOwner($this->owner);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());
        $this->project->enrolOwner();
        $this->enrol($this->admin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->enrol($this->member, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->request = $this->enrol($this->requester, ParticipantRole::Member, ParticipantStatus::Pending);

        $dispatcher = $this->createStub(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (User $user, string $category, string $level, string $title, string $message, ?array $data = null, ?string $route = null): void {
            $this->dispatched[] = [$user, $category, $level, $title, $message, $data, $route];
        });
        $this->notifier = new JoinRequestNotifier($dispatcher);
    }

    public function testManagersAreNotifiedOfAJoinRequestWithTheProjectAndThePilotName(): void
    {
        $this->notifier->notifyManagers($this->request);

        self::assertSame([$this->owner, $this->admin], array_column($this->dispatched, 0));
        foreach ($this->dispatched as [, $category, $level, $title, $message, $data, $route]) {
            self::assertSame(Notification::CATEGORY_INDUSTRY, $category);
            self::assertSame(Notification::LEVEL_INFO, $level);
            self::assertStringContainsString(self::PROJECT_NAME, $title);
            self::assertStringContainsString(self::REQUESTER_NAME, $message);
            self::assertStringContainsString(self::PROJECT_NAME, $message);
            self::assertSame(
                ['projectId' => $this->project->getId()->toRfc4122(), 'memberId' => $this->request->getId()->toRfc4122()],
                $data,
            );
            self::assertSame('/industry/projects/' . $this->project->getId()->toRfc4122() . '?tab=members', $route);
        }
    }

    public function testNeitherMembersNorPendingNorRemovedAdminsAreNotifiedOfAJoinRequest(): void
    {
        $pendingAdmin = $this->createStub(User::class);
        $removedAdmin = $this->createStub(User::class);
        $this->enrol($pendingAdmin, ParticipantRole::Admin, ParticipantStatus::Pending);
        $this->enrol($removedAdmin, ParticipantRole::Admin, ParticipantStatus::Removed);

        $this->notifier->notifyManagers($this->request);

        self::assertSame([$this->owner, $this->admin], array_column($this->dispatched, 0));
    }

    public function testTheRequesterIsNotifiedWhenTheRequestIsAccepted(): void
    {
        $this->request->setStatus(ParticipantStatus::Accepted);

        $this->notifier->notifyRequester($this->request);

        self::assertCount(1, $this->dispatched);
        [$recipient, $category, $level, $title, $message, $data, $route] = $this->dispatched[0];
        self::assertSame($this->requester, $recipient);
        self::assertSame(Notification::CATEGORY_INDUSTRY, $category);
        self::assertSame(Notification::LEVEL_SUCCESS, $level);
        self::assertStringContainsString(self::PROJECT_NAME, $title);
        self::assertStringContainsString(self::PROJECT_NAME, $message);
        self::assertSame(['projectId' => $this->project->getId()->toRfc4122()], $data);
        self::assertSame('/industry/projects/' . $this->project->getId()->toRfc4122(), $route);
    }

    private function enrol(User $user, ParticipantRole $role, ParticipantStatus $status): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
        $this->project->addMember($participant);

        return $participant;
    }
}
