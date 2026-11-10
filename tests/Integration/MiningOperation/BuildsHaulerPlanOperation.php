<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningOperation;

use App\Entity\MiningEntry;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\Sde\DgmAttributeType;
use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Service\MiningOperation\MiningLedgerEntry;
use App\Service\MiningOperation\MiningLedgerReading;
use App\Tests\Functional\Api\MiningOperation\SeedsBoostShipSde;
use App\Tests\Integration\MiningSite\SeedsMiningSiteMarket;

trait BuildsHaulerPlanOperation
{
    use PersistsMiningOperations;
    use SeedsBoostShipSde;
    use SeedsMiningSiteMarket;

    private const string HAULER_PLAN_NOW = '2026-11-10T20:10:00+00:00';
    private const string HAULER_PLAN_STARTED_AT = '2026-11-10T20:00:00+00:00';
    private const string HAULER_PLAN_SCANNED_AT = '2026-11-10T20:09:00+00:00';
    private const string HAULER_PLAN_SITE_ETA = '2026-11-10T21:10:00+00:00';
    private const string HAULER_PLAN_LEDGER_DAY = '2026-11-10';
    private const string HAULER_PLAN_PREVIOUS_DAY = '2026-11-09';
    private const int OTHER_SOLAR_SYSTEM = 30_002_187;
    private const float ZEOLITES_VOLUME = 5.0;
    private const float ORCA_HANGAR_M3 = 40_000.0;
    private const float ORCA_HOLD_M3 = 150_000.0;
    private const int FLEET_HANGAR_CAPACITY_ATTRIBUTE = 912;
    private const int GENERAL_MINING_HOLD_CAPACITY_ATTRIBUTE = 1556;
    private const array SITE_TREND_RATES = [self::VELDSPAR => 200.0, self::SCORDITE => 100.0, self::ZEOLITES => 13.0];

    private User $aria;
    private User $brann;
    private User $cyra;
    private User $dorn;

    private function seedHaulerPlanSde(): void
    {
        $this->seedMiningSiteSde($this->em);
        $this->seedBoostShipSde($this->em);
        $zeolites = $this->em->find(InvType::class, self::ZEOLITES);
        \assert($zeolites instanceof InvType);
        $zeolites->setVolume(self::ZEOLITES_VOLUME);
        foreach ([self::FLEET_HANGAR_CAPACITY_ATTRIBUTE => 'fleetHangarCapacity', self::GENERAL_MINING_HOLD_CAPACITY_ATTRIBUTE => 'generalMiningHoldCapacity'] as $attributeId => $attributeName) {
            $this->em->persist((new DgmAttributeType())->setAttributeId($attributeId)->setAttributeName($attributeName));
        }
        $this->em->persist((new DgmTypeAttribute())->setTypeId(self::ORCA)->setAttributeId(self::FLEET_HANGAR_CAPACITY_ATTRIBUTE)->setValueInt((int) self::ORCA_HANGAR_M3));
        $this->em->persist((new DgmTypeAttribute())->setTypeId(self::ORCA)->setAttributeId(self::GENERAL_MINING_HOLD_CAPACITY_ATTRIBUTE)->setValueInt((int) self::ORCA_HOLD_M3));
    }

    /**
     * @param array<int, ?float> $trendRateByTypeId
     */
    private function persistHaulerPlanOperation(
        MiningOperationStatus $status = MiningOperationStatus::InProgress,
        ?MiningBoostShip $boostShip = MiningBoostShip::Orca,
        bool $withRecipient = true,
        bool $withSite = true,
        bool $readableStartSnapshots = true,
        array $trendRateByTypeId = self::SITE_TREND_RATES,
    ): MiningOperation {
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C1);
        $this->dorn = $this->persistMiningPilot($this->em, 'Dorn Achar', null);

        $operation = $this->persistMiningOperation($this->em, $this->aria, 'Anomalie Veldspar — 20:00', self::HAULER_PLAN_STARTED_AT, ownerCharacterNames: ['Aria Solberg']);
        $brannMembership = $this->addMiningMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $cyraMembership = $this->addMiningMembership($operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $operation->declareBoostFit($boostShip, null === $boostShip ? [] : [MiningCompressorKind::Asteroid]);
        $startedAt = new \DateTimeImmutable(self::HAULER_PLAN_STARTED_AT);
        $operation->start($startedAt, $this->em->find(MapSolarSystem::class, self::ALDRANETTE));
        $operation->setStatus($status);
        if ($withRecipient) {
            $operation->assignFleetStorage($brannMembership, 'OPS-HAUL');
        }
        $operation->membershipOf($this->aria)?->declareFleetStorage(MiningFleetStorageDelivery::All, null);
        $cyraMembership->declareFleetStorage(MiningFleetStorageDelivery::Partial, [self::SCORDITE => 2_000]);
        $this->em->persist($operation);
        $this->em->flush();

        $startRows = ['Aria Solberg' => [[self::VELDSPAR, 2_000]], 'Brann Holt' => [], 'Cyra Lune' => [[self::SCORDITE, 1_000]]];
        foreach ($startRows as $characterName => $rows) {
            $start = $this->haulerPlanEnrolmentOf($operation, $characterName)->scheduleStartSnapshot($startedAt);
            if ($readableStartSnapshots || 'Cyra Lune' !== $characterName) {
                $start->take($startedAt->modify('+2 seconds'), $this->haulerPlanReading($rows));
            } else {
                $start->fail(MiningLedgerSnapshotStatus::Failed, 'ESI server error');
            }
        }

        $this->persistLedgerEntry($this->aria, 'Aria Solberg', self::HAULER_PLAN_LEDGER_DAY, self::VELDSPAR, 10_000, self::ALDRANETTE);
        $this->persistLedgerEntry($this->brann, 'Brann Holt', self::HAULER_PLAN_LEDGER_DAY, self::VELDSPAR, 8_000, self::ALDRANETTE);
        $this->persistLedgerEntry($this->cyra, 'Cyra Lune', self::HAULER_PLAN_LEDGER_DAY, self::SCORDITE, 5_000, self::ALDRANETTE);
        $this->persistLedgerEntry($this->aria, 'Aria Solberg', self::HAULER_PLAN_PREVIOUS_DAY, self::VELDSPAR, 50_000, self::ALDRANETTE);
        $this->persistLedgerEntry($this->cyra, 'Cyra Lune', self::HAULER_PLAN_LEDGER_DAY, self::SCORDITE, 70_000, self::OTHER_SOLAR_SYSTEM);

        if ($withSite) {
            $this->persistHaulerPlanSite($operation, $trendRateByTypeId);
        }
        $this->em->flush();

        return $operation;
    }

    /**
     * @param array<int, ?float> $trendRateByTypeId
     */
    private function persistHaulerPlanSite(MiningOperation $operation, array $trendRateByTypeId): void
    {
        $site = (new MiningSite())
            ->setOwner($this->aria)
            ->setOperation($operation)
            ->setLabel('Anomalie Veldspar')
            ->setSolarSystemId(self::ALDRANETTE)
            ->setCreatedAt(new \DateTimeImmutable(self::HAULER_PLAN_STARTED_AT));
        $this->em->persist($site);
        $types = array_map(
            static fn (int $typeId, ?float $trendRate): array => ['typeId' => $typeId, 'status' => 'present', 'units' => 500_000, 'trendRate' => $trendRate],
            array_keys($trendRateByTypeId),
            $trendRateByTypeId,
        );
        $types[] = ['typeId' => self::BLUE_ICE, 'status' => 'depleted', 'units' => 0, 'trendRate' => 40.0];
        $this->em->persist(
            (new MiningSiteScan())
                ->setSite($site)
                ->setAuthor($this->aria)
                ->setScannedAt(new \DateTimeImmutable(self::HAULER_PLAN_SCANNED_AT))
                ->setTotalUnits(1_500_000)
                ->setTotalVolumeM3(100_000.0)
                ->setRemainingValue(15_000_000.0)
                ->setTrendEta(new \DateTimeImmutable(self::HAULER_PLAN_SITE_ETA))
                ->setTypes($types),
        );
    }

    private function persistLedgerEntry(User $user, string $characterName, string $day, int $typeId, int $quantity, int $solarSystemId): void
    {
        $this->em->persist(
            (new MiningEntry())
                ->setUser($user)
                ->setCharacterId($this->miningCharacters[$characterName]->getEveCharacterId())
                ->setCharacterName($characterName)
                ->setDate(new \DateTimeImmutable($day))
                ->setTypeId($typeId)
                ->setTypeName('type '.$typeId)
                ->setSolarSystemId($solarSystemId)
                ->setSolarSystemName('system '.$solarSystemId)
                ->setQuantity($quantity),
        );
    }

    /**
     * @param list<array{int, int}> $rows
     */
    private function haulerPlanReading(array $rows): MiningLedgerReading
    {
        return new MiningLedgerReading(
            array_map(static fn (array $row): MiningLedgerEntry => new MiningLedgerEntry(new \DateTimeImmutable(self::HAULER_PLAN_LEDGER_DAY), $row[0], self::ALDRANETTE, $row[1]), $rows),
            null,
        );
    }

    private function haulerPlanEnrolmentOf(MiningOperation $operation, string $characterName): MiningOperationCharacter
    {
        foreach ($operation->getMembers() as $member) {
            foreach ($member->getCharacters() as $enrolment) {
                if ($enrolment->getCharacter()->getName() === $characterName) {
                    return $enrolment;
                }
            }
        }

        throw new \LogicException(sprintf('%s is not enrolled', $characterName));
    }
}
