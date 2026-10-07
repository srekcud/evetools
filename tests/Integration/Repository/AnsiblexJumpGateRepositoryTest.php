<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AnsiblexJumpGate;
use App\Repository\AnsiblexJumpGateRepository;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Issue #34 : `deactivateStaleGates()` désactive les portes Ansiblex qu'aucune sync n'a vues
 * depuis le seuil (`lastSeenAt`, mis à jour par `touch()` à chaque sync qui voit la porte).
 */
final class AnsiblexJumpGateRepositoryTest extends IntegrationTestCase
{
    private const STALE_GATE_THRESHOLD = '-7 days';

    private static int $nextStructureId = 1_990_000_000_000;

    private AnsiblexJumpGateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(AnsiblexJumpGateRepository::class);
        // Désactive les portes périmées déjà présentes en base de test (transaction annulée en fin de test),
        // pour que le nombre de désactivations ne compte que les portes du test.
        $this->repository->deactivateStaleGates(new \DateTimeImmutable(self::STALE_GATE_THRESHOLD));
    }

    public function testDeactivateStaleGatesDeactivatesGatesNotSeenSinceTheThresholdAndKeepsRecentOnesActive(): void
    {
        $lastSeenEightDaysAgo = $this->createActiveGate(new \DateTimeImmutable('-8 days'));
        $lastSeenThirtyDaysAgo = $this->createActiveGate(new \DateTimeImmutable('-30 days'));
        $lastSeenSixDaysAgo = $this->createActiveGate(new \DateTimeImmutable('-6 days'));
        $lastSeenAnHourAgo = $this->createActiveGate(new \DateTimeImmutable('-1 hour'));
        $this->flushAndClear();

        $deactivatedCount = $this->repository->deactivateStaleGates(new \DateTimeImmutable(self::STALE_GATE_THRESHOLD));

        self::assertSame(2, $deactivatedCount);
        self::assertSame([
            $lastSeenEightDaysAgo => false,
            $lastSeenThirtyDaysAgo => false,
            $lastSeenSixDaysAgo => true,
            $lastSeenAnHourAgo => true,
        ], $this->activeFlagsByStructureId([$lastSeenEightDaysAgo, $lastSeenThirtyDaysAgo, $lastSeenSixDaysAgo, $lastSeenAnHourAgo]));
    }

    public function testDeactivateStaleGatesDoesNotCountGatesAlreadyInactive(): void
    {
        $inactiveLastSeenTenDaysAgo = $this->createActiveGate(new \DateTimeImmutable('-10 days'));
        $this->flushAndClear();
        $this->repository->deactivateStaleGates(new \DateTimeImmutable(self::STALE_GATE_THRESHOLD));

        self::assertSame(0, $this->repository->deactivateStaleGates(new \DateTimeImmutable(self::STALE_GATE_THRESHOLD)));
        self::assertSame(
            [$inactiveLastSeenTenDaysAgo => false],
            $this->activeFlagsByStructureId([$inactiveLastSeenTenDaysAgo]),
        );
    }

    private function createActiveGate(\DateTimeImmutable $lastSeenAt): int
    {
        $structureId = self::$nextStructureId++;
        $gate = (new AnsiblexJumpGate())
            ->setStructureId($structureId)
            ->setName('1DQ1-A » 8QT-H4 - Test Bridge')
            // Une seule porte par paire (source, destination) : contrainte unique.
            ->setSourceSolarSystemId($structureId % 1_000_000)
            ->setSourceSolarSystemName('1DQ1-A')
            ->setDestinationSolarSystemId(30004712)
            ->setDestinationSolarSystemName('8QT-H4')
            ->setIsActive(true)
            ->setLastSeenAt($lastSeenAt);
        $this->em->persist($gate);

        return $structureId;
    }

    /**
     * @param list<int> $structureIds
     *
     * @return array<int, bool>
     */
    private function activeFlagsByStructureId(array $structureIds): array
    {
        $this->em->clear();
        $activeFlags = [];
        foreach ($structureIds as $structureId) {
            $gate = $this->repository->find($structureId);
            self::assertNotNull($gate);
            $activeFlags[$structureId] = $gate->isActive();
        }

        return $activeFlags;
    }
}
