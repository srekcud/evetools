<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Market;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Market\CreateAlertInput;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\MarketAlertPriceResolver;
use App\Service\StructureMarketService;
use App\State\Processor\Market\CreateAlertProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateAlertProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class CreateAlertProcessorTest extends TestCase
{
    private const int PREFERRED_STRUCTURE_ID = 1000000000011;
    private const int FOLLOWED_STRUCTURE_ID = 1000000000022;
    private const int UNFOLLOWED_STRUCTURE_ID = 1000000000033;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $em;
    private InvTypeRepository&Stub $invTypeRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private StructureMarketService&Stub $structureMarketService;
    private CreateAlertProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->structureMarketService = $this->createStub(StructureMarketService::class);

        $this->processor = new CreateAlertProcessor(
            $this->security,
            $this->em,
            $this->invTypeRepository,
            new MarketAlertPriceResolver($this->jitaMarketService, $this->structureMarketService),
        );
    }

    // ===========================================
    // Successful creation
    // ===========================================

    public function testCreatesAlertAndReturnsResource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0]);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'above';
        $input->threshold = 10.0;
        $input->priceSource = 'jita_sell';

        $result = $this->processor->process($input, new Post());

        $this->assertInstanceOf(MarketAlertResource::class, $result);
        $this->assertSame(34, $result->typeId);
        $this->assertSame('Tritanium', $result->typeName);
        $this->assertSame('above', $result->direction);
        $this->assertSame(10.0, $result->threshold);
        $this->assertSame('jita_sell', $result->priceSource);
        $this->assertSame('active', $result->status);
        $this->assertSame(12.0, $result->currentPrice);
        $this->assertNull($result->triggeredAt);
        $this->assertNull($result->triggeredPrice);
        $this->assertNotEmpty($result->createdAt);
    }

    public function testCreatesAlertWithBuyPriceSource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 9.0]);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'below';
        $input->threshold = 8.0;
        $input->priceSource = 'jita_buy';

        $result = $this->processor->process($input, new Post());

        $this->assertSame(9.0, $result->currentPrice);
        $this->assertSame('jita_buy', $result->priceSource);
    }

    // ===========================================
    // Validation errors
    // ===========================================

    public function testThrowsBadRequestForInvalidTypeId(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->invTypeRepository->method('findByTypeId')->willReturn(null);

        $input = new CreateAlertInput();
        $input->typeId = 99999;
        $input->direction = 'above';
        $input->threshold = 10.0;
        $input->priceSource = 'jita_sell';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid type ID');

        $this->processor->process($input, new Post());
    }

    public function testThrowsBadRequestForInvalidDirection(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'invalid';
        $input->threshold = 10.0;
        $input->priceSource = 'jita_sell';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid direction');

        $this->processor->process($input, new Post());
    }

    public function testThrowsBadRequestForInvalidPriceSource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'above';
        $input->threshold = 10.0;
        $input->priceSource = 'invalid_source';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid price source');

        $this->processor->process($input, new Post());
    }

    public function testThrowsBadRequestForNonPositiveThreshold(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'above';
        $input->threshold = 0.0;
        $input->priceSource = 'jita_sell';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Threshold must be positive');

        $this->processor->process($input, new Post());
    }

    public function testThrowsBadRequestForNegativeThreshold(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'above';
        $input->threshold = -5.0;
        $input->priceSource = 'jita_sell';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Threshold must be positive');

        $this->processor->process($input, new Post());
    }

    // ===========================================
    // Auth
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'above';
        $input->threshold = 10.0;
        $input->priceSource = 'jita_sell';

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor->process($input, new Post());
    }

    // ===========================================
    // Entity persistence
    // ===========================================

    public function testEntityIsPersistedWithCorrectData(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0]);

        $this->em->expects($this->once())->method('persist')
            ->with($this->callback(function (MarketPriceAlert $alert): bool {
                return $alert->getTypeId() === 34
                    && $alert->getTypeName() === 'Tritanium'
                    && $alert->getDirection() === AlertDirection::Above
                    && $alert->getThreshold() === 10.0
                    && $alert->getPriceSource() === AlertPriceSource::JitaSell;
            }));

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'above';
        $input->threshold = 10.0;
        $input->priceSource = 'jita_sell';

        $this->processor->process($input, new Post());
    }

    public function testStructureAlertDefaultsToThePreferredStructure(): void
    {
        $user = $this->createUserWithStructures(self::PREFERRED_STRUCTURE_ID, [self::FOLLOWED_STRUCTURE_ID]);
        $this->givenAuthenticated($user);
        $this->structureMarketService->method('getLowestSellPrices')->willReturnMap([
            [self::PREFERRED_STRUCTURE_ID, [34], [34 => 95.0]],
            [self::FOLLOWED_STRUCTURE_ID, [34], [34 => 120.0]],
        ]);
        $persisted = [];
        $this->em->expects($this->once())->method('persist')->willReturnCallback(static function (MarketPriceAlert $alert) use (&$persisted): void {
            $persisted[] = $alert;
        });

        $result = $this->processor->process($this->structureInput('structure_sell', null), new Post());

        $this->assertSame(self::PREFERRED_STRUCTURE_ID, $persisted[0]->getStructureId());
        $this->assertSame(self::PREFERRED_STRUCTURE_ID, $result->structureId);
        $this->assertSame(95.0, $result->currentPrice);
    }

    #[Group('issue-880')]
    public function testStructureAlertDefaultsToTheFirstFollowedStructureWhenNoStructureIsPreferred(): void
    {
        $user = $this->createUserWithStructures(null, [self::FOLLOWED_STRUCTURE_ID, self::PREFERRED_STRUCTURE_ID]);
        $this->givenAuthenticated($user);
        $this->structureMarketService->method('getLowestSellPrices')->willReturnMap([
            [self::FOLLOWED_STRUCTURE_ID, [34], [34 => 120.0]],
            [self::PREFERRED_STRUCTURE_ID, [34], [34 => 95.0]],
        ]);
        $persisted = [];
        $this->em->expects($this->once())->method('persist')->willReturnCallback(static function (MarketPriceAlert $alert) use (&$persisted): void {
            $persisted[] = $alert;
        });

        $result = $this->processor->process($this->structureInput('structure_sell', null), new Post());

        $this->assertSame(self::FOLLOWED_STRUCTURE_ID, $persisted[0]->getStructureId());
        $this->assertSame(self::FOLLOWED_STRUCTURE_ID, $result->structureId);
        $this->assertSame(120.0, $result->currentPrice);
    }

    public function testStructureAlertIsCreatedOnAFollowedStructure(): void
    {
        $user = $this->createUserWithStructures(self::PREFERRED_STRUCTURE_ID, [self::FOLLOWED_STRUCTURE_ID]);
        $this->givenAuthenticated($user);
        $this->structureMarketService->method('getHighestBuyPrices')->willReturnMap([
            [self::PREFERRED_STRUCTURE_ID, [34], [34 => 80.0]],
            [self::FOLLOWED_STRUCTURE_ID, [34], [34 => 105.0]],
        ]);
        $persisted = [];
        $this->em->expects($this->once())->method('persist')->willReturnCallback(static function (MarketPriceAlert $alert) use (&$persisted): void {
            $persisted[] = $alert;
        });

        $result = $this->processor->process($this->structureInput('structure_buy', self::FOLLOWED_STRUCTURE_ID), new Post());

        $this->assertSame(self::FOLLOWED_STRUCTURE_ID, $persisted[0]->getStructureId());
        $this->assertSame(self::FOLLOWED_STRUCTURE_ID, $result->structureId);
        $this->assertSame('Followed hub', $result->structureName);
        $this->assertSame(105.0, $result->currentPrice);
    }

    public function testStructureAlertIsAcceptedOnThePreferredStructureWhenNoStructureIsFollowed(): void
    {
        $user = $this->createUserWithStructures(self::PREFERRED_STRUCTURE_ID, []);
        $this->givenAuthenticated($user);
        $this->em->expects($this->once())->method('persist');

        $result = $this->processor->process($this->structureInput('structure_sell', self::PREFERRED_STRUCTURE_ID), new Post());

        $this->assertSame(self::PREFERRED_STRUCTURE_ID, $result->structureId);
        $this->assertSame('Preferred hub', $result->structureName);
    }

    public function testThrowsBadRequestForAStructureThatIsNeitherPreferredNorFollowed(): void
    {
        $user = $this->createUserWithStructures(self::PREFERRED_STRUCTURE_ID, [self::FOLLOWED_STRUCTURE_ID]);
        $this->givenAuthenticated($user);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('The structure must be your preferred or a followed market structure');

        $this->processor->process($this->structureInput('structure_sell', self::UNFOLLOWED_STRUCTURE_ID), new Post());
    }

    public function testThrowsBadRequestForAStructureIdOnAJitaPriceSource(): void
    {
        $user = $this->createUserWithStructures(self::PREFERRED_STRUCTURE_ID, [self::FOLLOWED_STRUCTURE_ID]);
        $this->givenAuthenticated($user);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('A structure can only be set on a structure price source');

        $this->processor->process($this->structureInput('jita_sell', self::FOLLOWED_STRUCTURE_ID), new Post());
    }

    public function testThrowsBadRequestForAStructureSourceWithoutAnyMarketStructureNorStructureId(): void
    {
        $user = $this->createUserWithStructures(null, []);
        $this->givenAuthenticated($user);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('You must set a preferred market structure before using structure price alerts');

        $this->processor->process($this->structureInput('structure_buy', null), new Post());
    }

    public function testJitaAlertIsPersistedWithoutStructure(): void
    {
        $user = $this->createUserWithStructures(self::PREFERRED_STRUCTURE_ID, []);
        $this->givenAuthenticated($user);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0]);
        $persisted = [];
        $this->em->expects($this->once())->method('persist')->willReturnCallback(static function (MarketPriceAlert $alert) use (&$persisted): void {
            $persisted[] = $alert;
        });

        $result = $this->processor->process($this->structureInput('jita_sell', null), new Post());

        $this->assertNull($persisted[0]->getStructureId());
        $this->assertNull($result->structureId);
        $this->assertNull($result->structureName);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function givenAuthenticated(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);
    }

    private function structureInput(string $priceSource, ?int $structureId): CreateAlertInput
    {
        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = 'below';
        $input->threshold = 100.0;
        $input->priceSource = $priceSource;
        $input->structureId = $structureId;

        return $input;
    }

    /** @param list<int> $followedStructureIds */
    private function createUserWithStructures(?int $preferredStructureId, array $followedStructureIds): User
    {
        $user = (new User())
            ->setPreferredMarketStructureId($preferredStructureId)
            ->setPreferredMarketStructureName($preferredStructureId === null ? null : 'Preferred hub');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::v4());
        foreach ($followedStructureIds as $followedStructureId) {
            $user->addMarketStructure($followedStructureId, 'Followed hub');
        }

        return $user;
    }

    private function createUserStub(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        return $user;
    }
}
