<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\AlertStatus;
use App\Repository\MarketPriceAlertRepository;
use App\Tests\Integration\IntegrationTestCase;

final class MarketPriceAlertRepositoryTest extends IntegrationTestCase
{
    private const int ACTIVE_TYPE_ID = 34;
    private const int TRIGGERED_TYPE_ID = 35;
    private const int EXPIRED_TYPE_ID = 36;
    private const int STRUCTURE_TYPE_ID = 37;
    private const int STRUCTURE_ID = 1035466617946;

    private MarketPriceAlertRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(MarketPriceAlertRepository::class);
        $user = $this->createUser();
        $this->createAlert($user, self::ACTIVE_TYPE_ID, AlertStatus::Active);
        $this->createAlert($user, self::TRIGGERED_TYPE_ID, AlertStatus::Triggered);
        $this->createAlert($user, self::EXPIRED_TYPE_ID, AlertStatus::Expired);
        $this->flushAndClear();
    }

    public function testGetWatchedAlertTypeIdsReturnsActiveAndTriggeredTypesOnly(): void
    {
        $typeIds = $this->repository->getWatchedAlertTypeIds();
        sort($typeIds);

        self::assertSame([self::ACTIVE_TYPE_ID, self::TRIGGERED_TYPE_ID], $typeIds);
    }

    public function testFindTriggeredAlertsReturnsOnlyTriggeredAlert(): void
    {
        $alerts = $this->repository->findTriggeredAlerts();

        self::assertCount(1, $alerts);
        self::assertSame(self::TRIGGERED_TYPE_ID, $alerts[0]->getTypeId());
        self::assertSame(AlertStatus::Triggered, $alerts[0]->getStatus());
    }

    public function testStructureIdSurvivesAReloadAndStaysNullForAJitaAlert(): void
    {
        $user = $this->createUser();
        $this->em->persist(
            (new MarketPriceAlert())
                ->setUser($user)
                ->setTypeId(self::STRUCTURE_TYPE_ID)
                ->setTypeName('Type ' . self::STRUCTURE_TYPE_ID)
                ->setDirection(AlertDirection::Below)
                ->setThreshold(95.5)
                ->setPriceSource(AlertPriceSource::StructureSell)
                ->setStructureId(self::STRUCTURE_ID),
        );
        $this->flushAndClear();

        $structureIdsByTypeId = [];
        foreach ($this->repository->findActiveAlerts() as $alert) {
            $structureIdsByTypeId[$alert->getTypeId()] = $alert->getStructureId();
        }
        ksort($structureIdsByTypeId);

        self::assertSame([self::ACTIVE_TYPE_ID => null, self::STRUCTURE_TYPE_ID => self::STRUCTURE_ID], $structureIdsByTypeId);
    }

    private function createAlert(User $user, int $typeId, AlertStatus $status): void
    {
        $alert = (new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId($typeId)
            ->setTypeName('Type ' . $typeId)
            ->setDirection(AlertDirection::Above)
            ->setThreshold(10.0 + $typeId)
            ->setPriceSource(AlertPriceSource::JitaSell)
            ->setStatus($status);
        $this->em->persist($alert);
    }
}
