<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Industry\Project\UpdateParticipantInput;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Industry\Project\UpdateParticipantProcessor;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\Tests\Unit\State\Provider\Industry\Project\GroupProjectAccessProfiles;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateParticipantProcessor::class)]
class UpdateParticipantProcessorTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const string ONLY_THE_OWNER_MANAGES_AN_ADMIN = '403 Only the project owner can manage an admin';
    private const string ONLY_THE_OWNER_PROMOTES = '403 Only the project owner can promote members to admin';
    private const string MEMBER_NOT_FOUND = '404 Member not found';

    private Security&Stub $security;
    private EntityManagerInterface&Stub $entityManager;
    private UpdateParticipantProcessor $processor;
    private int $flushes = 0;
    private array $publishedPayloads = [];

    private User $adminA;
    private IndustryProjectParticipant $ownerMembership;
    private IndustryProjectParticipant $adminAMembership;
    private IndustryProjectParticipant $adminBMembership;
    private IndustryProjectParticipant $memberM;
    private IndustryProjectParticipant $pendingQ;
    private User $memberMUser;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->ownerMembership = $this->sabreBatchMemberships[0];
        $this->adminA = $this->userOfCorporation(self::FOREIGN_CORPORATION_ID);
        $this->adminAMembership = $this->addSabreBatchMembership($this->adminA, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->adminBMembership = $this->addSabreBatchMembership($this->userOfCorporation(self::OWNER_CORPORATION_ID), ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->memberMUser = $this->userOfCorporation(self::FOREIGN_CORPORATION_ID);
        $this->memberM = $this->addSabreBatchMembership($this->memberMUser, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->pendingQ = $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), ParticipantRole::Member, ParticipantStatus::Pending);

        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $memberRepository = $this->sabreBatchMemberRepository();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedPayloads[] = json_decode($update->getData(), true);

            return 'update-id';
        });

        $this->processor = new UpdateParticipantProcessor(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $memberRepository,
            $this->entityManager,
            new ProjectAccessChecker($memberRepository),
            new ProjectResourceMapper(),
            new MercurePublisherService($hub, new NullLogger()),
        );
    }

    public function testAdminCannotDemoteAnotherAdmin(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, role: 'member'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($this->adminBMembership));
    }

    public function testAdminCannotAcceptAnotherAdminBeforeTheBodyIsValidated(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, status: 'accepted'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($this->adminBMembership));
    }

    public function testAdminCannotDemoteHimself(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminAMembership, role: 'member'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($this->adminAMembership));
    }

    public function testAdminCannotChangeTheRoleOfARemovedAdmin(): void
    {
        $removedAdmin = $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), ParticipantRole::Admin, ParticipantStatus::Removed);
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($removedAdmin, role: 'member'));
        self::assertSame(['admin', 'removed', 0], $this->stateOf($removedAdmin));
    }

    public function testAdminTargetingAnAdminIsRefusedBeforeAnInvalidRoleIsReported(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, role: 'overlord'));
    }

    public function testAdminTargetingAnAdminIsRefusedBeforeTheOwnerOnlyPromotion(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, role: 'admin'));
    }

    public function testAdminTargetingAnAdminIsRefusedBeforeAnInvalidStatusIsReported(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, status: 'removed'));
    }

    public function testOwnerDemotesAnAdmin(): void
    {
        $this->actAs($this->sabreBatchOwner);

        self::assertSame('granted member/accepted', $this->patch($this->adminBMembership, role: 'member'));
        self::assertSame(['member', 'accepted', 1], $this->stateOf($this->adminBMembership));
    }

    public function testOwnerPromotesAMemberToAdmin(): void
    {
        $this->actAs($this->sabreBatchOwner);

        self::assertSame('granted admin/accepted', $this->patch($this->memberM, role: 'admin'));
        self::assertSame(['admin', 'accepted', 1], $this->stateOf($this->memberM));
    }

    public function testAdminCannotPromoteAMember(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_PROMOTES, $this->patch($this->memberM, role: 'admin'));
        self::assertSame(['member', 'accepted', 0], $this->stateOf($this->memberM));
    }

    public function testAdminLeavesTheRoleOfAMemberToMember(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('granted member/accepted', $this->patch($this->memberM, role: 'member'));
        self::assertSame(['member', 'accepted', 1], $this->stateOf($this->memberM));
    }

    public function testAdminAcceptsAPendingMember(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('granted member/accepted', $this->patch($this->pendingQ, status: 'accepted'));
        self::assertSame(['member', 'accepted', 1], $this->stateOf($this->pendingQ));
    }

    #[Group('issue-321')]
    public function testAcceptingAPendingMemberPublishesMemberAcceptedWithTheMemberIdAndThePilotName(): void
    {
        $this->pendingQ->getUser()->method('getMainCharacter')->willReturn((new Character())->setName('Vex Tanner'));
        $this->actAs($this->adminA);

        $this->patch($this->pendingQ, status: 'accepted');

        self::assertCount(1, $this->publishedPayloads);
        self::assertSame('member_accepted', $this->publishedPayloads[0]['action']);
        self::assertSame($this->sabreBatch->getId()->toRfc4122(), $this->publishedPayloads[0]['projectId']);
        self::assertSame(
            ['memberId' => $this->pendingQ->getId()->toRfc4122(), 'characterName' => 'Vex Tanner'],
            $this->publishedPayloads[0]['data'],
        );
    }

    #[Group('issue-321')]
    public function testChangingOnlyTheRoleOfAnAcceptedMemberPublishesNoMemberAccepted(): void
    {
        $this->actAs($this->sabreBatchOwner);

        $this->patch($this->memberM, role: 'admin');

        self::assertSame([], $this->publishedPayloads);
    }

    #[Group('issue-321')]
    public function testARefusedAcceptancePublishesNothing(): void
    {
        $this->actAs($this->adminA);

        $this->patch($this->memberM, status: 'accepted');

        self::assertSame([], $this->publishedPayloads);
    }

    public function testPlainMemberCannotManageAnAdmin(): void
    {
        $this->actAs($this->memberMUser);

        self::assertSame(self::ADMIN_OR_OWNER_ONLY, $this->patch($this->adminBMembership, role: 'member'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($this->adminBMembership));
    }

    public function testAdminCannotModifyTheOwner(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('400 Cannot modify the project owner', $this->patch($this->ownerMembership, role: 'member'));
        self::assertSame(['owner', 'accepted', 0], $this->stateOf($this->ownerMembership));
    }

    public function testInvalidRoleOnAMemberIsABadRequest(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('400 Invalid role "overlord"', $this->patch($this->memberM, role: 'overlord'));
    }

    public function testOwnerRoleCannotBeGivenToAMember(): void
    {
        $this->actAs($this->sabreBatchOwner);

        self::assertSame('400 Invalid role "owner"', $this->patch($this->memberM, role: 'owner'));
        self::assertSame(['member', 'accepted', 0], $this->stateOf($this->memberM));
    }

    public function testStatusOtherThanAcceptedIsABadRequest(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('400 Status can only be changed to "accepted"', $this->patch($this->pendingQ, status: 'removed'));
        self::assertSame(['member', 'pending', 0], $this->stateOf($this->pendingQ));
    }

    public function testAcceptedMemberCannotBeAcceptedAgain(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('400 Only pending members can be accepted', $this->patch($this->memberM, status: 'accepted'));
    }

    public function testMissingUserIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->patch($this->memberM, role: 'member'));
    }

    public function testUnknownProjectIsNotFoundBeforeTheAccessCheck(): void
    {
        $this->actAs($this->memberMUser);

        self::assertSame(self::PROJECT_NOT_FOUND, $this->patch($this->memberM, role: 'member', projectId: Uuid::v4()));
    }

    public function testUnknownMemberIsNotFoundAfterTheAccessCheck(): void
    {
        $this->actAs($this->adminA);
        $unknownMember = new IndustryProjectParticipant();
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'id'))->setValue($unknownMember, Uuid::v4());

        self::assertSame(self::MEMBER_NOT_FOUND, $this->patch($unknownMember, role: 'member'));
    }

    public function testPlainMemberIsRefusedBeforeAnUnknownMemberIsReported(): void
    {
        $this->actAs($this->memberMUser);
        $unknownMember = new IndustryProjectParticipant();
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'id'))->setValue($unknownMember, Uuid::v4());

        self::assertSame(self::ADMIN_OR_OWNER_ONLY, $this->patch($unknownMember, role: 'member'));
    }

    public function testMemberOfAnotherProjectIsNotFound(): void
    {
        $this->actAs($this->adminA);
        $otherProject = (new IndustryProject());
        $otherProject->setOwner($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));
        $otherProjectAdmin = $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), ParticipantRole::Admin, ParticipantStatus::Accepted);
        $otherProjectAdmin->setProject($otherProject);

        self::assertSame(self::MEMBER_NOT_FOUND, $this->patch($otherProjectAdmin, role: 'member'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($otherProjectAdmin));
    }

    public function testMissingMemberIdIsNotFound(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(
            self::MEMBER_NOT_FOUND,
            $this->outcomeOf(fn () => $this->processor->process(new UpdateParticipantInput(), new Patch(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()])),
        );
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function patch(IndustryProjectParticipant $target, ?string $role = null, ?string $status = null, ?Uuid $projectId = null): string
    {
        $input = new UpdateParticipantInput();
        $input->role = $role;
        $input->status = $status;

        return $this->outcomeOf(function () use ($input, $target, $projectId): string {
            $resource = $this->processor->process($input, new Patch(), [
                'projectId' => ($projectId ?? $this->sabreBatch->getId())->toRfc4122(),
                'id' => $target->getId()->toRfc4122(),
            ]);

            return sprintf('granted %s/%s', $resource->role, $resource->status);
        });
    }

    private function stateOf(IndustryProjectParticipant $member): array
    {
        return [$member->getRole()->value, $member->getStatus()->value, $this->flushes];
    }
}
