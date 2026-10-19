<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Ledger\MiningEntryResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Service\OreValueService;
use App\State\Provider\Ledger\MiningEntryCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(MiningEntryCollectionProvider::class)]
final class MiningEntryCollectionProviderTest extends TestCase
{
    private const int PYROXERES = 1224;
    private const int ORE_MISSING_FROM_SDE = 62568;

    public function testEntryOfAnOreMissingFromTheSdeIsListedWithAnUnknownNameAndItsQuantityAndValue(): void
    {
        $user = new User();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('findByUserAndDateRange')->willReturn([
            $this->entry($user, '2026-10-08', self::ORE_MISSING_FROM_SDE, null, 15_587, 41.5),
            $this->entry($user, '2026-10-07', self::PYROXERES, 'Pyroxeres', 431, 512.0),
        ]);
        $oreValueService = $this->createStub(OreValueService::class);
        $oreValueService->method('getBatchOrePrices')->willReturn([]);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $provider = new MiningEntryCollectionProvider($security, $miningEntryRepository, $requestStack, $oreValueService);

        $entries = $provider->provide(new GetCollection());

        $this->assertSame([
            [self::ORE_MISSING_FROM_SDE, null, 15_587, 41.5, 646_860.5],
            [self::PYROXERES, 'Pyroxeres', 431, 512.0, 220_672.0],
        ], array_map(static fn (MiningEntryResource $entry): array => [
            $entry->typeId,
            $entry->typeName,
            $entry->quantity,
            $entry->unitPrice,
            $entry->totalValue,
        ], $entries));
    }

    private function entry(User $user, string $date, int $typeId, ?string $typeName, int $quantity, float $unitPrice): MiningEntry
    {
        return (new MiningEntry())
            ->setUser($user)
            ->setCharacterId(91000002)
            ->setCharacterName('Dorin Halk')
            ->setDate(new \DateTimeImmutable($date))
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setSolarSystemId(30002813)
            ->setSolarSystemName('Tama')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice);
    }
}
