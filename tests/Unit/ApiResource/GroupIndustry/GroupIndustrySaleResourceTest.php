<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupIndustrySaleResource::class)]
#[Group('issue-241')]
final class GroupIndustrySaleResourceTest extends TestCase
{
    private const int O_MAIN_EVE_CHARACTER_ID = 2120000001;
    private const int O_ALT_EVE_CHARACTER_ID = 2120000002;
    private const int A_MAIN_EVE_CHARACTER_ID = 2120000011;

    public function testSaleSoldByTheAltOfItsRecorderExposesTheAltAsSellerAndTheMainAsRecorder(): void
    {
        $recorder = new User();
        $oMain = (new Character())->setEveCharacterId(self::O_MAIN_EVE_CHARACTER_ID)->setName('O-Main');
        $oAlt = (new Character())->setEveCharacterId(self::O_ALT_EVE_CHARACTER_ID)->setName('O-Alt');
        $recorder->addCharacter($oMain)->addCharacter($oAlt)->setMainCharacter($oMain);

        $resource = GroupIndustrySaleResource::fromSale($this->sabreSale(3, 61_250_000.0, $recorder)->setSeller($oAlt));

        self::assertSame(
            [self::O_ALT_EVE_CHARACTER_ID, 'O-Alt', 'O-Main', 183_750_000.0],
            [$resource->sellerCharacterId, $resource->sellerCharacterName, $resource->recordedByCharacterName, $resource->totalPrice],
        );
    }

    public function testSaleWithoutSellerExposesNeitherSellerIdentifierNorSellerName(): void
    {
        $recorder = new User();
        $oMain = (new Character())->setEveCharacterId(self::O_MAIN_EVE_CHARACTER_ID)->setName('O-Main');
        $recorder->addCharacter($oMain)->setMainCharacter($oMain);

        $resource = GroupIndustrySaleResource::fromSale($this->sabreSale(2, 60_000_000.0, $recorder)->setSeller(null));

        self::assertSame(
            [null, null, 'O-Main', 120_000_000.0],
            [$resource->sellerCharacterId, $resource->sellerCharacterName, $resource->recordedByCharacterName, $resource->totalPrice],
        );
    }

    public function testSellerNameIsTheNameOfTheCharacterEvenWhenItsAccountHasNoMainCharacter(): void
    {
        $aMain = (new Character())->setEveCharacterId(self::A_MAIN_EVE_CHARACTER_ID)->setName('A-Main');
        (new User())->addCharacter($aMain);

        $resource = GroupIndustrySaleResource::fromSale($this->sabreSale(1, 62_000_000.0, null)->setSeller($aMain));

        self::assertSame(
            [self::A_MAIN_EVE_CHARACTER_ID, 'A-Main', null, 62_000_000.0],
            [$resource->sellerCharacterId, $resource->sellerCharacterName, $resource->recordedByCharacterName, $resource->totalPrice],
        );
    }

    public function testSaleRecordedFromAWalletTransactionIsLinkedToIt(): void
    {
        $oMain = (new Character())->setEveCharacterId(self::O_MAIN_EVE_CHARACTER_ID)->setName('O-Main');
        $t1 = (new CachedWalletTransaction())
            ->setCharacter($oMain)
            ->setTransactionId(6_100_000_001)
            ->setIsBuy(false)
            ->setTypeId(22456)
            ->setQuantity(3)
            ->setUnitPrice(61_250_000.0)
            ->setDate(new \DateTimeImmutable('2026-10-24T21:05:00+00:00'))
            ->setLocationId(60003760)
            ->setClientId(2119000001);

        $resource = GroupIndustrySaleResource::fromSale($this->sabreSale(3, 61_250_000.0, null)->setSeller($oMain)->setWalletTransaction($t1));

        self::assertSame([true, 183_750_000.0], [$resource->linkedToWalletTransaction, $resource->totalPrice]);
    }

    public function testSaleRecordedByHandIsNotLinkedToAnyWalletTransaction(): void
    {
        $resource = GroupIndustrySaleResource::fromSale($this->sabreSale(3, 61_250_000.0, null)->setWalletTransaction(null));

        self::assertSame([false, 183_750_000.0], [$resource->linkedToWalletTransaction, $resource->totalPrice]);
    }

    private function sabreSale(int $quantity, float $unitPrice, ?User $recordedBy): GroupIndustrySale
    {
        $sale = (new GroupIndustrySale())
            ->setTypeId(0)
            ->setTypeName('Sabre')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->recalculateTotalPrice()
            ->setSoldAt(new \DateTimeImmutable('2026-10-24T21:05:00+00:00'));
        if ($recordedBy !== null) {
            $sale->setRecordedBy($recordedBy);
        }
        (new \ReflectionProperty(GroupIndustrySale::class, 'id'))->setValue($sale, Uuid::v4());

        return $sale;
    }
}
