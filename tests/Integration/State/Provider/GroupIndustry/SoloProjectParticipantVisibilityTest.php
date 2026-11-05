<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\GroupIndustry\AvailableGroupProjectsProvider;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectCollectionProvider;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

#[Group('issue-362')]
final class SoloProjectParticipantVisibilityTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_362_101;

    private string $ownerId;
    private string $viewerId;
    private string $soloProjectId;
    private string $groupProjectId;
    private string $corporationMemberProjectId;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->createUserWithMainCharacter('Kaelen Voss');
        $corporationMember = $this->createUserWithMainCharacter('Brann Holt');
        $viewer = $this->createUserWithMainCharacter('Cyra Lune');

        $soloProject = (new IndustryProject())
            ->setOwner($owner)
            ->setName('Rifter by steps')
            ->setStatus(GroupProjectStatus::Draft)
            ->setProductTypeId(587)
            ->setRuns(10)
            ->addStep(
                (new IndustryProjectStep())
                    ->setBlueprintTypeId(586)
                    ->setProductTypeId(587)
                    ->setQuantity(10)
                    ->setRuns(10)
                    ->setDepth(0),
            );
        $soloProject->addMember($this->ownerParticipant($owner));

        $groupProject = (new IndustryProject())
            ->setOwner($owner)
            ->setName('Sabre by items')
            ->setStatus(GroupProjectStatus::Published)
            ->setProductTypeId(22456)
            ->setRuns(5)
            ->addItem(
                (new IndustryProjectProduct())
                    ->setTypeId(22456)
                    ->setTypeName('Sabre')
                    ->setMeLevel(10)
                    ->setTeLevel(20)
                    ->setRuns(5),
            );
        $groupProject->addMember($this->ownerParticipant($owner));

        $corporationMemberProject = (new IndustryProject())
            ->setOwner($corporationMember)
            ->setName('Jackdaw by items')
            ->setStatus(GroupProjectStatus::Published)
            ->setProductTypeId(34828)
            ->setRuns(20)
            ->addItem(
                (new IndustryProjectProduct())
                    ->setTypeId(34828)
                    ->setTypeName('Jackdaw')
                    ->setMeLevel(8)
                    ->setTeLevel(16)
                    ->setRuns(20),
            );
        $corporationMemberProject->addMember($this->ownerParticipant($corporationMember));

        $this->em->persist($soloProject);
        $this->em->persist($groupProject);
        $this->em->persist($corporationMemberProject);
        $this->em->flush();

        $this->ownerId = (string) $owner->getId();
        $this->viewerId = (string) $viewer->getId();
        $this->soloProjectId = (string) $soloProject->getId();
        $this->groupProjectId = (string) $groupProject->getId();
        $this->corporationMemberProjectId = (string) $corporationMemberProject->getId();
        $this->em->clear();
    }

    public function testAcceptedMembershipsOfTheOwnerOnlyReachTheProjectWithItems(): void
    {
        $memberRepository = self::getContainer()->get(IndustryProjectParticipantRepository::class);
        $owner = $this->user($this->ownerId);

        self::assertSame([$this->groupProjectId], $memberRepository->findAcceptedProjectIds($owner));
        self::assertSame(
            [$this->groupProjectId],
            array_map(
                static fn (IndustryProjectParticipant $membership): string => (string) $membership->getProject()->getId(),
                $memberRepository->findAcceptedWithProjectsByUser($owner),
            ),
        );
    }

    public function testGroupProjectsOfTheOwnerListTheProjectWithItemsOnlyAsOwner(): void
    {
        $owner = $this->user($this->ownerId);

        $resources = $this->provider(GroupProjectCollectionProvider::class, $owner)->provide(new GetCollection());

        self::assertCount(1, $resources);
        self::assertSame($this->groupProjectId, $resources[0]->id);
        self::assertSame('owner', $resources[0]->myRole);
    }

    public function testAvailableProjectsOfACorporationMemberListTheProjectWithItemsAndNotTheSoloProject(): void
    {
        $viewerResources = $this->provider(AvailableGroupProjectsProvider::class, $this->user($this->viewerId))->provide(new GetCollection());
        $ownerResources = $this->provider(AvailableGroupProjectsProvider::class, $this->user($this->ownerId))->provide(new GetCollection());

        $viewerProjectIds = array_map(static fn ($resource): string => $resource->id, $viewerResources);
        sort($viewerProjectIds);
        $expectedProjectIds = [$this->groupProjectId, $this->corporationMemberProjectId];
        sort($expectedProjectIds);
        self::assertSame($expectedProjectIds, $viewerProjectIds);
        self::assertNotContains($this->soloProjectId, $viewerProjectIds);
        self::assertSame(
            [$this->corporationMemberProjectId],
            array_map(static fn ($resource): string => $resource->id, $ownerResources),
        );
    }

    private function user(string $userId): User
    {
        $user = $this->em->find(User::class, $userId);
        \assert($user instanceof User);

        return $user;
    }

    private function provider(string $providerClass, User $user): AvailableGroupProjectsProvider|GroupProjectCollectionProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $container = self::getContainer();

        if ($providerClass === AvailableGroupProjectsProvider::class) {
            return new AvailableGroupProjectsProvider(
                $security,
                $container->get(IndustryProjectRepository::class),
                $container->get(IndustryProjectParticipantRepository::class),
                $container->get(GroupIndustryResourceMapper::class),
                $container->get(IndustryProjectContributionRepository::class),
            );
        }

        return new GroupProjectCollectionProvider(
            $security,
            $container->get(IndustryProjectParticipantRepository::class),
            $container->get(GroupIndustryResourceMapper::class),
            $container->get(IndustryProjectContributionRepository::class),
        );
    }

    private function createUserWithMainCharacter(string $characterName): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName)->setCorporationId(self::CORPORATION_ID));

        return $user;
    }

    private function ownerParticipant(User $user): IndustryProjectParticipant
    {
        return (new IndustryProjectParticipant())
            ->setUser($user)
            ->setRole(GroupMemberRole::Owner)
            ->setStatus(GroupMemberStatus::Accepted);
    }
}
