<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningValuation;

use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningValuationProfileRepository;
use App\Service\MiningValuation\MiningValuationProfileReader;
use App\Service\MiningValuation\MiningValuationSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningValuationProfileReader::class)]
#[CoversClass(MiningValuationSettings::class)]
#[Group('issue-532')]
final class MiningValuationProfileReaderTest extends TestCase
{
    private const int STRUCTURE_ID = 60003760;

    public function testAUserWithoutProfileGetsJitaBuyCompressedWithoutStructureNorTaxAndIsNotPersisted(): void
    {
        $user = new User();
        $repository = $this->createMock(MiningValuationProfileRepository::class);
        $repository->expects($this->once())->method('findOneBy')->with($this->identicalTo(['user' => $user]))->willReturn(null);

        $settings = (new MiningValuationProfileReader($repository))->read($user);

        $this->assertEquals(
            new MiningValuationSettings(MiningValuationMarket::Jita, MarketSide::Buy, MiningValuationForm::Compressed, null, null, 0.0, false),
            $settings,
        );
    }

    public function testAStoredProfileIsReadFieldForFieldAndFlaggedPersisted(): void
    {
        $profile = (new MiningValuationProfile())
            ->setMarket(MiningValuationMarket::Structure)
            ->setSide(MarketSide::Sell)
            ->setForm(MiningValuationForm::Reprocessed)
            ->setStructureId(self::STRUCTURE_ID)
            ->setStructureName('Refinery B-1')
            ->setExportTax(850.0);
        $repository = $this->createStub(MiningValuationProfileRepository::class);
        $repository->method('findOneBy')->willReturn($profile);

        $settings = (new MiningValuationProfileReader($repository))->read(new User());

        $this->assertEquals(
            new MiningValuationSettings(MiningValuationMarket::Structure, MarketSide::Sell, MiningValuationForm::Reprocessed, self::STRUCTURE_ID, 'Refinery B-1', 850.0, true),
            $settings,
        );
    }

    public function testAStoredProfileOnJitaKeepsItsRememberedStructure(): void
    {
        $profile = (new MiningValuationProfile())
            ->setStructureId(self::STRUCTURE_ID)
            ->setStructureName('Refinery B-1');
        $repository = $this->createStub(MiningValuationProfileRepository::class);
        $repository->method('findOneBy')->willReturn($profile);

        $settings = (new MiningValuationProfileReader($repository))->read(new User());

        $this->assertSame([MiningValuationMarket::Jita, self::STRUCTURE_ID, 'Refinery B-1', true], [$settings->market, $settings->structureId, $settings->structureName, $settings->persisted]);
    }
}
