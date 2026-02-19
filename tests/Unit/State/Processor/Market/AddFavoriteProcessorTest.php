<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Market;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Market\CreateFavoriteInput;
use App\ApiResource\Market\MarketFavoriteResource;
use App\Entity\MarketFavorite;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\MarketFavoriteRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\State\Processor\Market\AddFavoriteProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AddFavoriteProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class AddFavoriteProcessorTest extends TestCase
{
    private Security&Stub $security;
    private EntityManagerInterface&MockObject $em;
    private MarketFavoriteRepository&Stub $favoriteRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private AddFavoriteProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->favoriteRepository = $this->createStub(MarketFavoriteRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);

        $this->processor = new AddFavoriteProcessor(
            $this->security,
            $this->em,
            $this->favoriteRepository,
            $this->invTypeRepository,
            $this->jitaMarketService,
        );
    }

    // ===========================================
    // Successful creation
    // ===========================================

    public function testCreatesFavoriteAndReturnsResource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);
        $this->favoriteRepository->method('findByUserAndType')->willReturn(null);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.50]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 5.00]);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $input = new CreateFavoriteInput();
        $input->typeId = 34;

        $result = $this->processor->process($input, new Post());

        $this->assertInstanceOf(MarketFavoriteResource::class, $result);
        $this->assertSame(34, $result->typeId);
        $this->assertSame('Tritanium', $result->typeName);
        $this->assertSame(5.50, $result->jitaSell);
        $this->assertSame(5.00, $result->jitaBuy);
        $this->assertNotEmpty($result->createdAt);
    }

    // ===========================================
    // Validation errors
    // ===========================================

    public function testThrowsBadRequestForInvalidTypeId(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->invTypeRepository->method('findByTypeId')->willReturn(null);

        $input = new CreateFavoriteInput();
        $input->typeId = 99999;

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid type ID');

        $this->processor->process($input, new Post());
    }

    public function testThrowsConflictWhenAlreadyFavorited(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $existingFavorite = $this->createStub(MarketFavorite::class);
        $this->favoriteRepository->method('findByUserAndType')->willReturn($existingFavorite);

        $input = new CreateFavoriteInput();
        $input->typeId = 34;

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Already in favorites');

        $this->processor->process($input, new Post());
    }

    // ===========================================
    // Auth
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $input = new CreateFavoriteInput();
        $input->typeId = 34;

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
        $this->favoriteRepository->method('findByUserAndType')->willReturn(null);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.50]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 5.00]);

        $this->em->expects($this->once())->method('persist')
            ->with($this->callback(function (MarketFavorite $fav): bool {
                return $fav->getTypeId() === 34;
            }));

        $input = new CreateFavoriteInput();
        $input->typeId = 34;

        $this->processor->process($input, new Post());
    }

    // ===========================================
    // No persist/flush on validation failure
    // ===========================================

    public function testNoPersistOnInvalidType(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->invTypeRepository->method('findByTypeId')->willReturn(null);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $input = new CreateFavoriteInput();
        $input->typeId = 99999;

        try {
            $this->processor->process($input, new Post());
        } catch (BadRequestHttpException) {
            // Expected
        }
    }

    public function testNoPersistOnDuplicate(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);
        $this->favoriteRepository->method('findByUserAndType')->willReturn($this->createStub(MarketFavorite::class));

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $input = new CreateFavoriteInput();
        $input->typeId = 34;

        try {
            $this->processor->process($input, new Post());
        } catch (ConflictHttpException) {
            // Expected
        }
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
