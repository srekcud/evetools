<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\Project\ProjectBomLineResource;
use App\Entity\Character;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Provider\Industry\Project\ProjectBomProvider;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\PlanningBlueprintNames;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStock;
use App\Service\Industry\Project\StartedJobConsumption;
use App\Service\Industry\Project\StartedJobConsumptionReader;
use App\Service\Industry\Project\ProjectJobLocationResolver;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectBomProvider::class)]
class ProjectBomProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const int FEROX = 16227;
    private const int NOCXIUM_ATOMS = 16637;
    private const int UNKNOWN_PRODUCT = 99002;

    private Security&Stub $security;
    private IndustryProjectRepository&Stub $projectRepository;
    private IndustryProjectBomLineRepository&Stub $bomItemRepository;
    private IndustryProjectParticipantRepository&Stub $memberRepository;
    private ProjectBomProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);

        $this->provider = new ProjectBomProvider(
            $this->security,
            $this->projectRepository,
            $this->bomItemRepository,
            new ProjectAccessChecker($this->memberRepository),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->createStub(ProjectJobLocationResolver::class),
            $this->containerFulfillmentOf(new ProjectContainerStock('no_container_name')),
            $this->blueprintNames(),
        );
    }

    // ===========================================
    // Auth
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    public function testThrowsNotFoundWhenProjectDoesNotExist(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->projectRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    public function testThrowsAccessDeniedWhenUserIsNotMemberNorSameCorp(): void
    {
        $owner = $this->createUserStub(98000001);
        $user = $this->createUserStub(98000099); // Different corp

        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);
        $this->memberRepository->method('findOneBy')->willReturn(null);

        $this->expectException(AccessDeniedHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    // ===========================================
    // Access grants
    // ===========================================

    public function testOwnerCanAccessBom(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    public function testAcceptedMemberCanAccessBom(): void
    {
        $owner = $this->createUserStub(98000001);
        $user = $this->createUserStub(98000099);

        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);

        $member = new IndustryProjectParticipant();
        $member->setUser($user);
        $member->setProject($project);
        $member->setRole(ParticipantRole::Member);
        $member->setStatus(ParticipantStatus::Accepted);
        $this->memberRepository->method('findOneBy')->willReturn($member);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    public function testCorpmateOfTheOwnerWhoIsNotAMemberIsRefusedTheBom(): void
    {
        $owner = $this->createUserStub(98000001);
        $corpmate = $this->createUserStub(98000001);

        $this->security->method('getUser')->willReturn($corpmate);

        $project = (new IndustryProject());
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);
        $this->memberRepository->method('findOneBy')->willReturn(null);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    // ===========================================
    // BOM mapping
    // ===========================================

    #[Group('issue-339')]
    public function testJobMatchesShowTheMainCharacterOfTheOwnerToAnotherPlayerAndTheAltToTheOwner(): void
    {
        $main = (new Character())->setEveCharacterId(2120000001)->setName('O-Main');
        $alt = (new Character())->setEveCharacterId(2120000002)->setName('O-Alt');
        $owner = (new User())->addCharacter($main)->addCharacter($alt)->setMainCharacter($main);
        $member = $this->createUserStub(98000099);
        $project = (new IndustryProject())->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);
        $participant = (new IndustryProjectParticipant())->setUser($member)->setProject($project)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $this->memberRepository->method('findOneBy')->willReturn($participant);
        $line = $this->createBomItem(34, 'Hammerhead II', 10, 0, null, true, 'final', 'manufacturing', null, 0, 0, 10);
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(1)->setStatus('active')->setRuns(10)->setCharacterName('O-Alt'));
        $this->bomItemRepository->method('findBy')->willReturn([$line]);
        $installersByViewer = [];

        foreach ([$member, $owner] as $viewer) {
            $security = $this->createStub(Security::class);
            $security->method('getUser')->willReturn($viewer);
            $provider = new ProjectBomProvider($security, $this->projectRepository, $this->bomItemRepository, new ProjectAccessChecker($this->memberRepository), $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryProjectContributionRepository::class), $this->createStub(ProjectJobLocationResolver::class), $this->containerFulfillmentOf(new ProjectContainerStock('no_container_name')), $this->blueprintNames());
            $installersByViewer[] = $provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()])[0]->jobMatches[0]['characterName'];
        }

        $this->assertSame(['O-Main', 'O-Alt'], $installersByViewer);
    }


    #[Group('issue-981')]
    public function testPurchasesCarryTheNameOfTheirBuyerAndWhetherTheViewerBoughtThem(): void
    {
        $ownerMain = (new Character())->setEveCharacterId(2981000001)->setName('Aria Solberg');
        $owner = (new User())->addCharacter($ownerMain)->setMainCharacter($ownerMain);
        $memberMain = (new Character())->setEveCharacterId(2981000002)->setName('Cora Vance');
        $member = (new User())->addCharacter($memberMain)->setMainCharacter($memberMain);
        (new \ReflectionProperty(User::class, 'id'))->setValue($owner, Uuid::v4());
        (new \ReflectionProperty(User::class, 'id'))->setValue($member, Uuid::v4());
        $project = (new IndustryProject())->setOwner($owner);
        $project->enrolOwner();
        $ownerParticipant = $project->getMembers()->first();
        $memberParticipant = (new IndustryProjectParticipant())->setUser($member)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $project->addMember($memberParticipant);
        $this->projectRepository->method('find')->willReturn($project);
        $this->memberRepository->method('findOneBy')->willReturn($memberParticipant);
        $line = $this->createBomItem(35, 'Pyerite', 5000, 0, 6.0, false, null, null, null, null, null, null);
        $line->addPurchase((new IndustryProjectPurchase())->setBuyer($ownerParticipant)->setTypeId(35)->setQuantity(60)->setUnitPrice(6.0)->setTotalPrice(360.0)->setSource('esi_wallet'));
        $line->addPurchase((new IndustryProjectPurchase())->setBuyer($memberParticipant)->setTypeId(35)->setQuantity(40)->setUnitPrice(8.5)->setTotalPrice(340.0)->setSource('esi_wallet'));
        $this->bomItemRepository->method('findBy')->willReturn([$line]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($member);
        $provider = new ProjectBomProvider($security, $this->projectRepository, $this->bomItemRepository, new ProjectAccessChecker($this->memberRepository), $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryProjectContributionRepository::class), $this->createStub(ProjectJobLocationResolver::class), $this->containerFulfillmentOf(new ProjectContainerStock('no_container_name')), $this->blueprintNames());

        $purchases = $provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()])[0]->purchases;

        self::assertCount(2, $purchases);
        self::assertArrayHasKey('isMine', $purchases[0]);
        self::assertArrayHasKey('buyerName', $purchases[0]);
        self::assertSame(
            [[60, false, 'Aria Solberg'], [40, true, 'Cora Vance']],
            array_map(static fn (array $purchase): array => [$purchase['quantity'], $purchase['isMine'], $purchase['buyerName']], $purchases),
        );
    }

    public function testMapsBomItemToResource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(34, 'Tritanium', 1000, 500, 5.5, false, null, null, null, null, null, null);
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertCount(1, $result);
        $resource = $result[0];
        $this->assertInstanceOf(ProjectBomLineResource::class, $resource);
        $this->assertSame(34, $resource->typeId);
        $this->assertSame('Tritanium', $resource->typeName);
        $this->assertSame(1000, $resource->requiredQuantity);
        $this->assertSame(500, $resource->fulfilledQuantity);
        $this->assertSame(500, $resource->remainingQuantity);
        $this->assertSame(50.0, $resource->fulfillmentPercent);
        $this->assertSame(5.5, $resource->estimatedPrice);
        $this->assertSame(5500.0, $resource->estimatedTotal); // 5.5 * 1000
        $this->assertFalse($resource->isJob);
        $this->assertNull($resource->jobGroup);
        $this->assertFalse($resource->isFulfilled);
    }

    public function testMapsJobBomItemToResource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(
            11399, 'Ferox', 100, 0, null,
            true, 'final', 'manufacturing', 34, 10, 20, 100,
        );
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $resource = $result[0];
        $this->assertTrue($resource->isJob);
        $this->assertSame('final', $resource->jobGroup);
        $this->assertSame('manufacturing', $resource->activityType);
        $this->assertSame(34, $resource->parentTypeId);
        $this->assertSame(10, $resource->meLevel);
        $this->assertSame(20, $resource->teLevel);
        $this->assertSame(100, $resource->runs);
        $this->assertNull($resource->estimatedTotal); // No price for jobs
    }

    public function testEstimatedTotalIsNullWhenNoPriceSet(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(34, 'Tritanium', 1000, 0, null, false, null, null, null, null, null, null);
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertNull($result[0]->estimatedTotal);
    }

    #[Group('issue-538')]
    public function testMaterialLineWithAPriceCarriesThePercentileSourceOfTheBom(): void
    {
        $this->assertSame(
            ['market' => 'jita', 'side' => 'sell', 'method' => 'percentile', 'coveragePercent' => null, 'percentile' => 5.0],
            $this->bomLineOf($this->createBomItem(34, 'Tritanium', 1000, 0, 5.0, false, null, null, null, null, null, null))->estimatedPriceSource,
        );
    }

    #[Group('issue-538')]
    public function testJobLineHasNoPriceSourceEvenWhenItCarriesAPrice(): void
    {
        $ferox = $this->createBomItem(11399, 'Ferox', 100, 0, 12.0, true, 'final', 'manufacturing', 34, 10, 20, 100);

        $this->assertNull($this->bomLineOf($ferox)->estimatedPriceSource);
    }

    #[Group('issue-538')]
    public function testMaterialLineWithoutPriceHasNoPriceSource(): void
    {
        $this->assertNull($this->bomLineOf($this->createBomItem(35, 'Pyerite', 600, 0, null, false, null, null, null, null, null, null))->estimatedPriceSource);
    }

    private function bomLineOf(IndustryProjectBomLine $bomItem): ProjectBomLineResource
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        return $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()])[0];
    }

    #[DataProvider('acceptedMemberAccessMatrix')]
    public function testBomIsReadByTheOwnerAndAcceptedMembersOnly(string $profile, string $expectedOutcome): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->readSabreBatchBom($this->sabreBatch->getId()));
    }

    public function testBomOfAnUnknownProjectIsNotFound(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->readSabreBatchBom(Uuid::v4()));
    }

    public function testBomListsOnlyTheItemsOfTheRequestedProject(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->sabreBatchOwner);
        $ferox = $this->createBomItem(11399, 'Ferox', 7, 2, null, true, 'final', 'manufacturing', null, 10, 20, 7);
        $tritanium = $this->createBomItem(34, 'Tritanium', 150, 40, null, false, null, null, 11399, null, null, null);
        $bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomItemRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $criteria === ['project' => $this->sabreBatch] ? [$ferox, $tritanium] : [],
        );
        $provider = new ProjectBomProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $bomItemRepository,
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->createStub(ProjectJobLocationResolver::class),
            $this->containerFulfillmentOf(new ProjectContainerStock('no_container_name')),
            $this->blueprintNames(),
        );

        $result = $provider->provide(new GetCollection(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame(['Ferox', 'Tritanium'], array_map(fn (ProjectBomLineResource $item) => $item->typeName, $result));
    }

    #[Group('issue-866')]
    public function testMaterialLineReadsTheContainerStockAsFulfilled(): void
    {
        $result = $this->readMaterialLineWith(new ProjectContainerStock(null, [11478 => 2]), 55, 0);

        self::assertSame(2, $result->fulfilledQuantity);
        self::assertSame(53, $result->remainingQuantity);
        self::assertEqualsWithDelta(3.64, $result->fulfillmentPercent, 0.01);
        self::assertFalse($result->isFulfilled);
        self::assertSame(53, $result->freeQuantity);
        self::assertSame(2, $result->containerQuantity);
        self::assertSame('known', $result->containerStockState);
    }

    #[Group('issue-866')]
    public function testMaterialLineOfAnUnreadableContainerKeepsTheContributionsAndFlagsTheStockUnknown(): void
    {
        $result = $this->readMaterialLineWith(new ProjectContainerStock('container_not_found'), 55, 10);

        self::assertSame(10, $result->fulfilledQuantity);
        self::assertSame(45, $result->remainingQuantity);
        self::assertNull($result->containerQuantity);
        self::assertSame('unknown', $result->containerStockState);
    }

    #[Group('issue-866')]
    public function testFinalJobLineIgnoresTheContainerStock(): void
    {
        $result = $this->readMaterialLineWith(new ProjectContainerStock(null, [11478 => 300]), 300, 20, true, 'final');

        self::assertSame(20, $result->fulfilledQuantity);
        self::assertNull($result->containerQuantity);
        self::assertSame('none', $result->containerStockState);
    }

    #[Group('issue-1013')]
    public function testComponentJobLineReadsTheContainerStockAsFulfilled(): void
    {
        $result = $this->readMaterialLineWith(new ProjectContainerStock(null, [11478 => 300]), 300, 20, true);

        self::assertSame(300, $result->fulfilledQuantity);
        self::assertSame(0, $result->remainingQuantity);
        self::assertTrue($result->isFulfilled);
        self::assertSame(300, $result->containerQuantity);
        self::assertSame('known', $result->containerStockState);
    }

    #[Group('issue-1013')]
    public function testComponentJobLinePartlyCoveredByTheContainerKeepsTheRemainingQuantityToLaunch(): void
    {
        $result = $this->readMaterialLineWith(new ProjectContainerStock(null, [11478 => 120]), 300, 0, true);

        self::assertSame(120, $result->fulfilledQuantity);
        self::assertSame(180, $result->remainingQuantity);
        self::assertFalse($result->isFulfilled);
        self::assertSame(120, $result->containerQuantity);
    }

    #[Group('issue-1013')]
    public function testComponentJobLineOfAnUnreadableContainerIsNeverCoveredAndFlagsTheStockUnknown(): void
    {
        $result = $this->readMaterialLineWith(new ProjectContainerStock('container_not_found'), 300, 20, true);

        self::assertSame(20, $result->fulfilledQuantity);
        self::assertFalse($result->isFulfilled);
        self::assertNull($result->containerQuantity);
        self::assertSame('unknown', $result->containerStockState);
    }

    #[Group('issue-1006')]
    public function testContainerStockNobodyClaimedIsUnclaimedAndValuedAtTheLaunchPrice(): void
    {
        $result = $this->readUnclaimedLine(ProjectStatus::InProgress, new ProjectContainerStock(null, [11478 => 80]), 100, 0, 12.5);

        self::assertSame(80, $result->unclaimedQuantity);
        self::assertSame(12.5, $result->launchPrice);
        self::assertSame(1000.0, $result->unclaimedValue);
    }

    #[Group('issue-1006')]
    public function testClaimedUnitsAreSubtractedFromTheUnclaimedQuantity(): void
    {
        $result = $this->readUnclaimedLine(ProjectStatus::InProgress, new ProjectContainerStock(null, [11478 => 80]), 100, 30, 12.5);

        self::assertSame(50, $result->unclaimedQuantity);
        self::assertSame(625.0, $result->unclaimedValue);
    }

    #[Group('issue-1006')]
    public function testUnclaimedStockOfAProjectWithoutALaunchPriceHasNoValue(): void
    {
        $result = $this->readUnclaimedLine(ProjectStatus::InProgress, new ProjectContainerStock(null, [11478 => 80]), 100, 0, null);

        self::assertSame(80, $result->unclaimedQuantity);
        self::assertNull($result->launchPrice);
        self::assertNull($result->unclaimedValue);
    }

    #[Group('issue-1006')]
    public function testUnclaimedQuantityIsUnknownWhenTheContainerStockIsUnreadable(): void
    {
        $result = $this->readUnclaimedLine(ProjectStatus::InProgress, new ProjectContainerStock('container_not_found'), 100, 0, 12.5);

        self::assertNull($result->unclaimedQuantity);
        self::assertNull($result->unclaimedValue);
    }

    #[Group('issue-1006')]
    public function testUnclaimedQuantityIsNotComputedBeforeTheProjectStarts(): void
    {
        $result = $this->readUnclaimedLine(ProjectStatus::Draft, new ProjectContainerStock(null, [11478 => 80]), 100, 0, null);

        self::assertNull($result->unclaimedQuantity);
    }

    #[Group('issue-1006')]
    public function testUnclaimedQuantityIsStillReadWhileTheProjectIsSelling(): void
    {
        $result = $this->readUnclaimedLine(ProjectStatus::Selling, new ProjectContainerStock(null, [11478 => 80]), 100, 0, 12.5);

        self::assertSame(80, $result->unclaimedQuantity);
    }

    #[Group('issue-1006')]
    public function testFinalJobLineHasNoUnclaimedStock(): void
    {
        $result = $this->readUnclaimedLine(ProjectStatus::InProgress, new ProjectContainerStock(null, [11478 => 80]), 100, 0, null, true, 'final');

        self::assertNull($result->unclaimedQuantity);
        self::assertNull($result->unclaimedValue);
    }

    #[Group('issue-1013')]
    public function testComponentJobLineCarriesTheUnclaimedStockValuedAtTheLaunchPrice(): void
    {
        $result = $this->readUnclaimedLine(ProjectStatus::InProgress, new ProjectContainerStock(null, [11478 => 80]), 100, 30, 12.5, true);

        self::assertSame(50, $result->unclaimedQuantity);
        self::assertSame(625.0, $result->unclaimedValue);
    }

    private function readUnclaimedLine(ProjectStatus $status, ProjectContainerStock $stock, int $required, int $claimed, ?float $launchPrice, bool $isJob = false, string $jobGroup = 'component'): ProjectBomLineResource
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $project = (new IndustryProject())->setOwner($user)->setStatus($status);
        $this->projectRepository->method('find')->willReturn($project);
        $line = $this->createBomItem(11478, 'R.A.M.- Starship Tech', $required, 0, null, $isJob, $isJob ? $jobGroup : null, null, null, null, null, null);
        $line->setLaunchPrice($launchPrice);
        $this->bomItemRepository->method('findBy')->willReturn([$line]);
        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('sumClaimedMaterialQuantityByLine')->willReturn([$line->getId()->toRfc4122() => $claimed]);
        $provider = new ProjectBomProvider(
            $this->security,
            $this->projectRepository,
            $this->bomItemRepository,
            new ProjectAccessChecker($this->memberRepository),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $contributions,
            $this->createStub(ProjectJobLocationResolver::class),
            $this->containerFulfillmentOf($stock),
            $this->blueprintNames(),
        );

        return $provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()])[0];
    }

    private function readMaterialLineWith(ProjectContainerStock $stock, int $required, int $contributed, bool $isJob = false, string $jobGroup = 'component'): ProjectBomLineResource
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $project = (new IndustryProject())->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);
        $line = $this->createBomItem(11478, 'R.A.M.- Starship Tech', $required, $contributed, null, $isJob, $isJob ? $jobGroup : null, null, null, null, null, null);
        $this->bomItemRepository->method('findBy')->willReturn([$line]);
        $provider = new ProjectBomProvider(
            $this->security,
            $this->projectRepository,
            $this->bomItemRepository,
            new ProjectAccessChecker($this->memberRepository),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->createStub(ProjectJobLocationResolver::class),
            $this->containerFulfillmentOf($stock),
            $this->blueprintNames(),
        );

        return $provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()])[0];
    }

    #[Group('issue-907')]
    public function testManufacturingJobLineCarriesTheNameOfItsBlueprint(): void
    {
        $line = $this->readSingleLine($this->createBomItem(self::FEROX, 'Ferox', 7, 0, null, true, 'final', 'manufacturing', null, 10, 20, 7));

        $this->assertSame('Ferox Blueprint', $line->blueprintName);
    }

    #[Group('issue-907')]
    public function testReactionJobLineCarriesTheNameOfItsFormula(): void
    {
        $line = $this->readSingleLine($this->createBomItem(self::NOCXIUM_ATOMS, 'Nocxium Atoms', 3, 0, null, true, 'component', 'reaction', null, null, null, 3));

        $this->assertSame('Nocxium Atoms Reaction Formula', $line->blueprintName);
    }

    #[Group('issue-907')]
    public function testJobLineWhoseBlueprintIsUnknownHasNoBlueprintName(): void
    {
        $line = $this->readSingleLine($this->createBomItem(self::UNKNOWN_PRODUCT, 'Mystery Hull', 1, 0, null, true, 'final', 'manufacturing', null, 10, 20, 1));

        $this->assertNull($line->blueprintName);
    }

    #[Group('issue-907')]
    public function testMaterialLineHasNoBlueprintNameEvenWhenSomethingBuildsIt(): void
    {
        $line = $this->readSingleLine($this->createBomItem(self::FEROX, 'Ferox', 7, 0, 5.5, false, null, null, null, null, null, null));

        $this->assertNull($line->blueprintName);
    }

    #[Group('issue-907')]
    public function testNamesOfSeveralJobLinesStayWithTheirOwnLine(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->projectRepository->method('find')->willReturn((new IndustryProject())->setOwner($user));
        $this->bomItemRepository->method('findBy')->willReturn([
            $this->createBomItem(self::FEROX, 'Ferox', 7, 0, null, true, 'final', 'manufacturing', null, 10, 20, 7),
            $this->createBomItem(self::NOCXIUM_ATOMS, 'Nocxium Atoms', 3, 0, null, true, 'component', 'reaction', null, null, null, 3),
            $this->createBomItem(self::UNKNOWN_PRODUCT, 'Mystery Hull', 1, 0, null, true, 'final', 'manufacturing', null, 10, 20, 1),
        ]);

        $names = array_map(
            static fn (ProjectBomLineResource $line): ?string => $line->blueprintName,
            $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]),
        );

        $this->assertSame(['Ferox Blueprint', 'Nocxium Atoms Reaction Formula', null], $names);
    }

    #[Group('issue-1005')]
    public function testReservationNoLongerCarriesAPendingQuantity(): void
    {
        $setup = $this->pendingScenario(150, 0);
        $line = $setup['line'];
        $reservations = $this->createStub(IndustryProjectLineReservationRepository::class);
        $reservations->method('findByProject')->willReturn([
            new IndustryProjectLineReservation($line, $setup['mine'], 50),
            new IndustryProjectLineReservation($line, $setup['other'], 30),
        ]);

        $resource = $this->readPendingScenario($setup, [$line->getId()->toRfc4122() => [$setup['mine']->getId()->toRfc4122() => 20]], $reservations);

        self::assertSame(
            [['quantity' => 50, 'isMine' => true], ['quantity' => 30, 'isMine' => false]],
            array_map(
                static fn (array $reservation): array => ['quantity' => $reservation['quantity'], 'isMine' => $reservation['isMine']],
                $resource->reservations,
            ),
        );
        foreach ($resource->reservations as $reservation) {
            self::assertArrayNotHasKey('pendingQuantity', $reservation);
        }
    }

    #[Group('issue-1301')]
    public function testReservationExposesItsExpiryFortyEightHoursAfterItWasMadeInAtomFormat(): void
    {
        $setup = $this->pendingScenario(150, 0);
        $line = $setup['line'];
        $reservations = $this->createStub(IndustryProjectLineReservationRepository::class);
        $reservations->method('findByProject')->willReturn([
            new IndustryProjectLineReservation($line, $setup['mine'], 50, new \DateTimeImmutable('2026-11-21 10:15:00')),
            new IndustryProjectLineReservation($line, $setup['other'], 30, new \DateTimeImmutable('2026-11-22 01:40:30')),
        ]);

        $resource = $this->readPendingScenario($setup, [], $reservations);

        self::assertCount(2, $resource->reservations);
        foreach ($resource->reservations as $reservation) {
            self::assertArrayHasKey('expiresAt', $reservation);
            $keys = array_keys($reservation);
            sort($keys);
            self::assertSame(['characterName', 'expiresAt', 'isMine', 'participantId', 'quantity'], $keys);
        }
        self::assertSame(
            [[50, '2026-11-23T10:15:00+00:00'], [30, '2026-11-24T01:40:30+00:00']],
            array_map(
                static fn (array $reservation): array => [$reservation['quantity'], $reservation['expiresAt']],
                $resource->reservations,
            ),
        );
    }

    #[Group('issue-1093')]
    public function testFulfilledLineWithReservationsHiddenStillCarriesItsPendingContributions(): void
    {
        $setup = $this->pendingScenario(150, 150);
        $line = $setup['line'];
        $reservations = $this->createStub(IndustryProjectLineReservationRepository::class);
        $reservations->method('findByProject')->willReturn([new IndustryProjectLineReservation($line, $setup['mine'], 150)]);
        $pending = [$line->getId()->toRfc4122() => [$setup['mine']->getId()->toRfc4122() => 120, $setup['other']->getId()->toRfc4122() => 30]];

        $resource = $this->readPendingScenario($setup, $pending, $reservations);

        self::assertTrue($resource->isFulfilled);
        self::assertSame([], $resource->reservations);
        self::assertSame(
            [
                ['participantId' => $setup['mine']->getId()->toRfc4122(), 'characterName' => 'Aria Solberg', 'quantity' => 120, 'isMine' => true],
                ['participantId' => $setup['other']->getId()->toRfc4122(), 'characterName' => 'Cora Vance', 'quantity' => 30, 'isMine' => false],
            ],
            $resource->pendingContributions,
        );
    }

    #[Group('issue-1093')]
    public function testPendingContributionWithoutReservationIsExposed(): void
    {
        $setup = $this->pendingScenario(150, 0);
        $line = $setup['line'];
        $pending = [$line->getId()->toRfc4122() => [$setup['other']->getId()->toRfc4122() => 40]];

        $resource = $this->readPendingScenario($setup, $pending, $this->createStub(IndustryProjectLineReservationRepository::class));

        self::assertSame([], $resource->reservations);
        self::assertSame(
            [['participantId' => $setup['other']->getId()->toRfc4122(), 'characterName' => 'Cora Vance', 'quantity' => 40, 'isMine' => false]],
            $resource->pendingContributions,
        );
    }

    #[Group('issue-1093')]
    public function testLineWithoutPendingContributionHasNone(): void
    {
        $setup = $this->pendingScenario(150, 0);

        $resource = $this->readPendingScenario($setup, [], $this->createStub(IndustryProjectLineReservationRepository::class));

        self::assertSame([], $resource->pendingContributions);
    }

    #[Group('issue-1093')]
    public function testPendingContributionsStayWithTheirOwnLine(): void
    {
        $setup = $this->pendingScenario(150, 0);
        $line = $setup['line'];
        $otherLine = $this->createBomItem(38, 'Nocxium', 80, 0, null, false, null, null, null, null, null, null);
        $setup['lines'][] = $otherLine;
        $pending = [$otherLine->getId()->toRfc4122() => [$setup['mine']->getId()->toRfc4122() => 7]];

        $resources = $this->readPendingScenarioLines($setup, $pending, $this->createStub(IndustryProjectLineReservationRepository::class));

        self::assertSame([[], [7]], array_map(
            static fn (ProjectBomLineResource $resource): array => array_column($resource->pendingContributions, 'quantity'),
            $resources,
        ));
        self::assertSame($line->getId()->toString(), $resources[0]->id);
    }

    #[Group('issue-1093')]
    public function testPendingContributionsAreSortedByCharacterNameWhateverTheQueryOrder(): void
    {
        $setup = $this->pendingScenario(150, 0);
        $line = $setup['line'];
        $pending = [$line->getId()->toRfc4122() => [$setup['other']->getId()->toRfc4122() => 40, $setup['mine']->getId()->toRfc4122() => 10]];

        $resource = $this->readPendingScenario($setup, $pending, $this->createStub(IndustryProjectLineReservationRepository::class));

        self::assertSame(['Aria Solberg', 'Cora Vance'], array_column($resource->pendingContributions, 'characterName'));
    }

    #[Group('issue-1093')]
    public function testPendingContributionsSortIgnoringTheCaseOfTheName(): void
    {
        $setup = $this->pendingScenario(150, 0);
        $setup['viewer']->getMainCharacter()->setName('zed Alpha');
        $line = $setup['line'];
        $pending = [$line->getId()->toRfc4122() => [$setup['mine']->getId()->toRfc4122() => 10, $setup['other']->getId()->toRfc4122() => 40]];

        $resource = $this->readPendingScenario($setup, $pending, $this->createStub(IndustryProjectLineReservationRepository::class));

        self::assertSame(['Cora Vance', 'zed Alpha'], array_column($resource->pendingContributions, 'characterName'));
    }

    #[Group('issue-1093')]
    public function testPendingContributionOfAnUnknownParticipantIsRefused(): void
    {
        $setup = $this->pendingScenario(150, 0);
        $line = $setup['line'];
        $pending = [$line->getId()->toRfc4122() => [Uuid::v4()->toRfc4122() => 10]];

        $this->expectException(\LogicException::class);

        $this->readPendingScenario($setup, $pending, $this->createStub(IndustryProjectLineReservationRepository::class));
    }

    /**
     * @return array{viewer: User, mine: IndustryProjectParticipant, other: IndustryProjectParticipant, project: IndustryProject, line: IndustryProjectBomLine, lines: list<IndustryProjectBomLine>}
     */
    private function pendingScenario(int $required, int $fulfilled): array
    {
        $viewerMain = (new Character())->setEveCharacterId(2981000011)->setName('Aria Solberg');
        $viewer = (new User())->addCharacter($viewerMain)->setMainCharacter($viewerMain);
        (new \ReflectionProperty(User::class, 'id'))->setValue($viewer, Uuid::v4());
        $otherMain = (new Character())->setEveCharacterId(2981000012)->setName('Cora Vance');
        $otherUser = (new User())->addCharacter($otherMain)->setMainCharacter($otherMain);
        (new \ReflectionProperty(User::class, 'id'))->setValue($otherUser, Uuid::v4());
        $project = (new IndustryProject())->setOwner($viewer);
        $project->enrolOwner();
        $mine = $project->getOwnerParticipant();
        $other = (new IndustryProjectParticipant())->setUser($otherUser)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $project->addMember($other);
        $line = $this->createBomItem(self::FEROX, 'Ferox', $required, $fulfilled, null, false, null, null, null, null, null, null);

        return ['viewer' => $viewer, 'mine' => $mine, 'other' => $other, 'project' => $project, 'line' => $line, 'lines' => [$line]];
    }

    /**
     * @param array{viewer: User, mine: IndustryProjectParticipant, other: IndustryProjectParticipant, project: IndustryProject, line: IndustryProjectBomLine, lines: list<IndustryProjectBomLine>} $setup
     * @param array<string, array<string, int>> $pending
     */
    private function readPendingScenario(array $setup, array $pending, IndustryProjectLineReservationRepository $reservations): ProjectBomLineResource
    {
        return $this->readPendingScenarioLines($setup, $pending, $reservations)[0];
    }

    /**
     * @param array{viewer: User, mine: IndustryProjectParticipant, other: IndustryProjectParticipant, project: IndustryProject, line: IndustryProjectBomLine, lines: list<IndustryProjectBomLine>} $setup
     * @param array<string, array<string, int>> $pending
     *
     * @return list<ProjectBomLineResource>
     */
    private function readPendingScenarioLines(array $setup, array $pending, IndustryProjectLineReservationRepository $reservations): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($setup['viewer']);
        $this->projectRepository->method('find')->willReturn($setup['project']);
        $this->bomItemRepository->method('findBy')->willReturn($setup['lines']);
        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('sumPendingQuantityByLineAndParticipant')->willReturn($pending);
        $provider = new ProjectBomProvider(
            $security,
            $this->projectRepository,
            $this->bomItemRepository,
            new ProjectAccessChecker($this->memberRepository),
            $reservations,
            $contributions,
            $this->createStub(ProjectJobLocationResolver::class),
            $this->containerFulfillmentOf(new ProjectContainerStock('no_container_name')),
            $this->blueprintNames(),
        );

        return array_values($provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]));
    }

    private function readSingleLine(IndustryProjectBomLine $line): ProjectBomLineResource
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->projectRepository->method('find')->willReturn((new IndustryProject())->setOwner($user));
        $this->bomItemRepository->method('findBy')->willReturn([$line]);

        return $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()])[0];
    }

    private function blueprintNames(): PlanningBlueprintNames
    {
        $products = $this->createStub(IndustryActivityProductRepository::class);
        $products->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId): ?IndustryActivityProduct => match ([$productTypeId, $activityId]) {
                [self::FEROX, 1] => (new IndustryActivityProduct())->setTypeId(16_228)->setActivityId(1)->setProductTypeId(self::FEROX)->setQuantity(1),
                [self::NOCXIUM_ATOMS, 11] => (new IndustryActivityProduct())->setTypeId(46_170)->setActivityId(11)->setProductTypeId(self::NOCXIUM_ATOMS)->setQuantity(1),
                default => null,
            },
        );
        $types = $this->createStub(InvTypeRepository::class);
        $types->method('findByTypeIds')->willReturnCallback(
            static function (array $typeIds): array {
                $known = [16_228 => 'Ferox Blueprint', 46_170 => 'Nocxium Atoms Reaction Formula'];
                $found = [];
                foreach ($typeIds as $typeId) {
                    if (isset($known[$typeId])) {
                        $found[$typeId] = (new InvType())->setTypeId($typeId)->setTypeName($known[$typeId]);
                    }
                }

                return $found;
            },
        );

        return new PlanningBlueprintNames($products, $types);
    }

    private function containerFulfillmentOf(ProjectContainerStock $stock): ProjectContainerFulfillment
    {
        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerStockOf')->willReturn($stock);

        $consumptionReader = $this->createStub(StartedJobConsumptionReader::class);
        $consumptionReader->method('of')->willReturn(StartedJobConsumption::none());

        return new ProjectContainerFulfillment($containerService, $consumptionReader);
    }

    private function readSabreBatchBom(Uuid $projectId): string
    {
        $bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomItemRepository->method('findBy')->willReturn([
            $this->createBomItem(11399, 'Ferox', 7, 2, null, true, 'final', 'manufacturing', null, 10, 20, 7),
        ]);
        $provider = new ProjectBomProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $bomItemRepository,
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->createStub(ProjectJobLocationResolver::class),
            $this->containerFulfillmentOf(new ProjectContainerStock('no_container_name')),
            $this->blueprintNames(),
        );

        return $this->outcomeOf(fn () => $provider->provide(new GetCollection(), ['projectId' => $projectId->toRfc4122()]));
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(?int $corporationId = null): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCorporationId')->willReturn($corporationId);

        return $user;
    }

    private function createBomItem(
        int $typeId,
        string $typeName,
        int $requiredQuantity,
        int $fulfilledQuantity,
        ?float $estimatedPrice,
        bool $isJob,
        ?string $jobGroup,
        ?string $activityType,
        ?int $parentTypeId,
        ?int $meLevel,
        ?int $teLevel,
        ?int $runs,
    ): IndustryProjectBomLine {
        $item = new IndustryProjectBomLine();
        $item->setTypeId($typeId);
        $item->setTypeName($typeName);
        $item->setRequiredQuantity($requiredQuantity);
        $item->setFulfilledQuantity($fulfilledQuantity);
        $item->setEstimatedPrice($estimatedPrice);
        $item->setIsJob($isJob);
        $item->setJobGroup($jobGroup);
        $item->setActivityType($activityType);
        $item->setParentTypeId($parentTypeId);
        $item->setMeLevel($meLevel);
        $item->setTeLevel($teLevel);
        $item->setRuns($runs);

        $reflection = new \ReflectionProperty(IndustryProjectBomLine::class, 'id');
        $reflection->setValue($item, Uuid::v4());

        return $item;
    }
}
