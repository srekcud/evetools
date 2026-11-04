<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonTax;

use App\Entity\Character;
use App\Entity\CorporationMiningExtraction;
use App\Entity\CorporationWalletDonation;
use App\Entity\EveToken;
use App\Entity\MoonObserverRecord;
use App\Entity\MoonTaxRefinerySetting;
use App\Entity\MoonTaxStatement;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\MoonTaxPeriodKind;
use App\Service\JitaMarketService;

trait SeedsMoonTax
{
    protected const int CORPORATION = 98_000_001;
    protected const int NEIGHBOR_CORPORATION = 98_000_002;
    protected const int STRANGER_CORPORATION = 98_000_003;
    protected const int LONER_CORPORATION = 98_000_004;
    protected const int OTHER_OWNER_CORPORATION = 98_000_009;
    protected const int ALLIANCE = 99_000_001;
    protected const int OTHER_ALLIANCE = 99_000_002;

    protected const int ILYRA_ID = 2_112_000_001;
    protected const int AUREL_ID = 2_112_000_002;
    protected const int BRANN_ID = 2_112_000_003;
    protected const int DAX_ID = 2_112_000_101;
    protected const int MIRA_ID = 2_112_000_102;
    protected const int TOLA_ID = 2_112_000_103;

    protected const int ROCK_HOLLOW_ID = 1_035_000_000_101;
    protected const int DEEP_CORE_ID = 1_035_000_000_102;
    protected const int MARKET_KEEPSTAR_ID = 1_035_000_000_199;

    protected const int CINNABAR = 45_510;
    protected const int COMPRESSED_CINNABAR = 62_580;
    protected const int EUXENITE = 45_495;
    protected const int COMPRESSED_EUXENITE = 62_575;
    protected const int ZEOLITES = 45_490;
    protected const int COMPRESSED_ZEOLITES = 62_570;
    protected const int XENOTIME = 45_520;
    protected const int COMPRESSED_XENOTIME = 62_585;
    protected const int LOPARITE = 45_525;
    protected const int VELDSPAR = 1_230;
    protected const int COMPRESSED_VELDSPAR = 62_516;

    protected const string JITA_SYNCED_AT = '2026-11-10T11:55:00+00:00';

    protected array $jitaBuyPrices = [
        self::COMPRESSED_CINNABAR => 3_000.0,
        self::COMPRESSED_EUXENITE => 800.0,
        self::COMPRESSED_ZEOLITES => 150.0,
        self::COMPRESSED_VELDSPAR => 15.0,
    ];

    protected array $usersByEveCharacterId = [];

    protected function replaceMoonOreJitaMarket(): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getBuyPrices')->willReturnCallback(fn (array $typeIds): array => array_combine($typeIds, array_map(fn (int $typeId): ?float => $this->jitaBuyPrices[$typeId] ?? null, $typeIds)));
        $jitaMarket->method('getLastSyncTime')->willReturn(new \DateTimeImmutable(self::JITA_SYNCED_AT));
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
    }

    protected function seedMoonOreSde(): void
    {
        $category = (new InvCategory())->setCategoryId(25)->setCategoryName('Asteroid');
        $this->em->persist($category);
        $groups = [];
        foreach ([462 => 'Veldspar', 1884 => 'Ubiquitous Moon Asteroids', 1920 => 'Common Moon Asteroids', 1921 => 'Uncommon Moon Asteroids', 1922 => 'Rare Moon Asteroids', 1923 => 'Exceptional Moon Asteroids'] as $groupId => $groupName) {
            $groups[$groupId] = (new InvGroup())->setGroupId($groupId)->setGroupName($groupName)->setCategory($category);
            $this->em->persist($groups[$groupId]);
        }
        foreach ([
            [self::CINNABAR, 'Cinnabar', 1923],
            [self::COMPRESSED_CINNABAR, 'Compressed Cinnabar', 1923],
            [self::EUXENITE, 'Euxenite', 1921],
            [self::COMPRESSED_EUXENITE, 'Compressed Euxenite', 1921],
            [self::ZEOLITES, 'Zeolites', 1884],
            [self::COMPRESSED_ZEOLITES, 'Compressed Zeolites', 1884],
            [self::XENOTIME, 'Xenotime', 1922],
            [self::COMPRESSED_XENOTIME, 'Compressed Xenotime', 1922],
            [self::LOPARITE, 'Loparite', 1922],
            [self::VELDSPAR, 'Veldspar', 462],
            [self::COMPRESSED_VELDSPAR, 'Compressed Veldspar', 462],
        ] as [$typeId, $typeName, $groupId]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($groups[$groupId])->setVolume(10.0)->setPortionSize(100)->setPublished(true));
        }
    }

    protected function persistCharacterOfCorporation(string $name, int $eveCharacterId, int $corporationId, array $roles, array $scopes = self::ALL_SCOPES): Character
    {
        $user = new User();
        $character = (new Character())->setName($name)->setEveCharacterId($eveCharacterId)->setCorporationId($corporationId)->setCorporationName('Corporation ' . $corporationId);
        $character->setEveToken(
            (new EveToken())
                ->setAccessToken(self::ACCESS_TOKEN_PREFIX . $eveCharacterId)
                ->setRefreshTokenEncrypted('refresh-token-' . $eveCharacterId)
                ->setAccessTokenExpiresAt(new \DateTimeImmutable('2026-11-10 12:30:00', new \DateTimeZone('UTC')))
                ->setScopes($scopes),
        );
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->esiResponses[self::rolesEndpoint($eveCharacterId)] = ['roles' => $roles];
        $this->usersByEveCharacterId[$eveCharacterId] = $user;

        return $character;
    }

    protected function persistCharacterForUser(User $user, string $name, int $eveCharacterId, int $corporationId): Character
    {
        $character = (new Character())->setName($name)->setEveCharacterId($eveCharacterId)->setCorporationId($corporationId)->setCorporationName('Corporation ' . $corporationId);
        $user->addCharacter($character);
        $this->em->persist($character);

        return $character;
    }

    protected function persistSetting(int $structureId, bool $open = true, MoonTaxPeriodKind $period = MoonTaxPeriodKind::Weekly, int $walletDivision = 1, int $corporationId = self::CORPORATION): MoonTaxRefinerySetting
    {
        $setting = (new MoonTaxRefinerySetting())
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setOpenToAlliance($open)
            ->setPeriod($period)
            ->setRateR4(2.0)
            ->setRateR8(4.0)
            ->setRateR16(8.0)
            ->setRateR32(9.0)
            ->setRateR64(10.0)
            ->setWalletDivision($walletDivision);
        $this->em->persist($setting);

        return $setting;
    }

    protected function persistExtraction(int $structureId, int $corporationId = self::CORPORATION): CorporationMiningExtraction
    {
        $extraction = (new CorporationMiningExtraction())
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setMoonId(40_301_001)
            ->setExtractionStartTime(new \DateTimeImmutable('2026-11-02T18:00:00+00:00'))
            ->setChunkArrivalTime(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'))
            ->setNaturalDecayTime(new \DateTimeImmutable('2026-11-05T23:00:00+00:00'));
        $this->em->persist($extraction);

        return $extraction;
    }

    protected function persistObserverRecord(int $structureId, int $characterId, int $typeId, string $lastUpdated, int $quantity, int $recordedCorporationId = self::CORPORATION, int $corporationId = self::CORPORATION): MoonObserverRecord
    {
        $record = (new MoonObserverRecord())
            ->setCorporationId($corporationId)
            ->setObserverId($structureId)
            ->setCharacterId($characterId)
            ->setTypeId($typeId)
            ->setLastUpdated(new \DateTimeImmutable($lastUpdated, new \DateTimeZone('UTC')))
            ->setQuantity($quantity)
            ->setRecordedCorporationId($recordedCorporationId);
        $this->em->persist($record);

        return $record;
    }

    protected function persistStatement(string $reference, int $characterId, string $periodStart, ?float $tax, int $structureId = self::ROCK_HOLLOW_ID, int $corporationId = self::CORPORATION, string $periodLength = '+7 days'): MoonTaxStatement
    {
        $start = new \DateTimeImmutable($periodStart . ' 00:00:00', new \DateTimeZone('UTC'));
        $statement = (new MoonTaxStatement())
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setCharacterId($characterId)
            ->setPeriodStart($start)
            ->setPeriodEnd($start->modify($periodLength))
            ->setReference($reference)
            ->setTax($tax)
            ->setRecordedCorporationId(self::CORPORATION)
            ->setOutsideAlliance(false);
        $this->em->persist($statement);

        return $statement;
    }

    protected function persistDonation(int $journalId, int $firstPartyId, float $amount, string $date, int $corporationId = self::CORPORATION): CorporationWalletDonation
    {
        $donation = (new CorporationWalletDonation())
            ->setCorporationId($corporationId)
            ->setJournalId($journalId)
            ->setFirstPartyId($firstPartyId)
            ->setAmount($amount)
            ->setDate(new \DateTimeImmutable($date, new \DateTimeZone('UTC')));
        $this->em->persist($donation);

        return $donation;
    }
}
