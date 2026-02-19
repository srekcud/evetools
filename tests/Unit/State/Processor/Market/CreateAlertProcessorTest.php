<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Market;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Market\CreateAlertInput;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\State\Processor\Market\CreateAlertProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
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
            $this->jitaMarketService,
            $this->structureMarketService,
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
        $input->direction = MarketPriceAlert::DIRECTION_ABOVE;
        $input->threshold = 10.0;
        $input->priceSource = MarketPriceAlert::SOURCE_JITA_SELL;

        $result = $this->processor->process($input, new Post());

        $this->assertInstanceOf(MarketAlertResource::class, $result);
        $this->assertSame(34, $result->typeId);
        $this->assertSame('Tritanium', $result->typeName);
        $this->assertSame(MarketPriceAlert::DIRECTION_ABOVE, $result->direction);
        $this->assertSame(10.0, $result->threshold);
        $this->assertSame(MarketPriceAlert::SOURCE_JITA_SELL, $result->priceSource);
        $this->assertSame(MarketPriceAlert::STATUS_ACTIVE, $result->status);
        $this->assertSame(12.0, $result->currentPrice);
        $this->assertNull($result->triggeredAt);
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
        $input->direction = MarketPriceAlert::DIRECTION_BELOW;
        $input->threshold = 8.0;
        $input->priceSource = MarketPriceAlert::SOURCE_JITA_BUY;

        $result = $this->processor->process($input, new Post());

        $this->assertSame(9.0, $result->currentPrice);
        $this->assertSame(MarketPriceAlert::SOURCE_JITA_BUY, $result->priceSource);
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
        $input->direction = MarketPriceAlert::DIRECTION_ABOVE;
        $input->threshold = 10.0;
        $input->priceSource = MarketPriceAlert::SOURCE_JITA_SELL;

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
        $input->priceSource = MarketPriceAlert::SOURCE_JITA_SELL;

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
        $input->direction = MarketPriceAlert::DIRECTION_ABOVE;
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
        $input->direction = MarketPriceAlert::DIRECTION_ABOVE;
        $input->threshold = 0.0;
        $input->priceSource = MarketPriceAlert::SOURCE_JITA_SELL;

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
        $input->direction = MarketPriceAlert::DIRECTION_ABOVE;
        $input->threshold = -5.0;
        $input->priceSource = MarketPriceAlert::SOURCE_JITA_SELL;

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
        $input->direction = MarketPriceAlert::DIRECTION_ABOVE;
        $input->threshold = 10.0;
        $input->priceSource = MarketPriceAlert::SOURCE_JITA_SELL;

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
                    && $alert->getDirection() === MarketPriceAlert::DIRECTION_ABOVE
                    && $alert->getThreshold() === 10.0
                    && $alert->getPriceSource() === MarketPriceAlert::SOURCE_JITA_SELL;
            }));

        $input = new CreateAlertInput();
        $input->typeId = 34;
        $input->direction = MarketPriceAlert::DIRECTION_ABOVE;
        $input->threshold = 10.0;
        $input->priceSource = MarketPriceAlert::SOURCE_JITA_SELL;

        $this->processor->process($input, new Post());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        return $user;
    }
}
