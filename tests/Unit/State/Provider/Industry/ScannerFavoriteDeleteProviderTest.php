<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Delete;
use App\Entity\IndustryScannerFavorite;
use App\Entity\User;
use App\Repository\IndustryScannerFavoriteRepository;
use App\State\Provider\Industry\ScannerFavoriteDeleteProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ScannerFavoriteDeleteProvider::class)]
#[Group('issue-206')]
final class ScannerFavoriteDeleteProviderTest extends TestCase
{
    private const int RIFTER_TYPE_ID = 587;
    private const int HELIUM_FUEL_BLOCK_TYPE_ID = 4247;

    public function testFavoriteOfAnotherPilotIsNotFoundBecauseOnlyTheFavoritesOfThePilotAreLookedUp(): void
    {
        $pilot = new User();
        $favoriteOfAnotherPilot = (new IndustryScannerFavorite())->setUser(new User())->setTypeId(self::RIFTER_TYPE_ID);
        $favoriteRepository = $this->createStub(IndustryScannerFavoriteRepository::class);
        $favoriteRepository->method('findByUserAndTypeId')->willReturnCallback(
            static fn (User $user, int $typeId): ?IndustryScannerFavorite => $user === $favoriteOfAnotherPilot->getUser() && $typeId === self::RIFTER_TYPE_ID ? $favoriteOfAnotherPilot : null,
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Scanner favorite not found');

        $this->provider($pilot, $favoriteRepository)->provide(new Delete(), ['typeId' => (string) self::RIFTER_TYPE_ID]);
    }

    public function testFavoriteOfThePilotIsAnsweredWithItsTypeAndCreationDate(): void
    {
        $pilot = new User();
        $favoriteOfThePilot = (new IndustryScannerFavorite())->setUser($pilot)->setTypeId(self::HELIUM_FUEL_BLOCK_TYPE_ID);
        $favoriteRepository = $this->createStub(IndustryScannerFavoriteRepository::class);
        $favoriteRepository->method('findByUserAndTypeId')->willReturnCallback(
            static fn (User $user, int $typeId): ?IndustryScannerFavorite => $user === $pilot && $typeId === self::HELIUM_FUEL_BLOCK_TYPE_ID ? $favoriteOfThePilot : null,
        );

        $resource = $this->provider($pilot, $favoriteRepository)->provide(new Delete(), ['typeId' => (string) self::HELIUM_FUEL_BLOCK_TYPE_ID]);

        self::assertSame(
            [self::HELIUM_FUEL_BLOCK_TYPE_ID, $favoriteOfThePilot->getCreatedAt()->format(\DateTimeInterface::ATOM)],
            [$resource->typeId, $resource->createdAt],
        );
    }

    private function provider(User $pilot, IndustryScannerFavoriteRepository $favoriteRepository): ScannerFavoriteDeleteProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($pilot);

        return new ScannerFavoriteDeleteProvider($security, $favoriteRepository);
    }
}
