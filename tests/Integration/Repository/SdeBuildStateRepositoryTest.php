<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\SdeBuildState;
use App\Repository\SdeBuildStateRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-848')]
final class SdeBuildStateRepositoryTest extends IntegrationTestCase
{
    private SdeBuildStateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(SdeBuildStateRepository::class);
    }

    public function testCurrentCreatesTheSingleRowOnDemandWithNothingRecorded(): void
    {
        self::assertSame(0, $this->repository->count([]));

        $state = $this->repository->current();
        $this->em->clear();

        self::assertSame(1, $this->repository->count([]));
        self::assertNull($state->getImportedBuildNumber());
        self::assertNull($state->getImportedReleaseDate());
        self::assertNull($state->getImportedAt());
        self::assertNull($state->getLatestBuildNumber());
        self::assertNull($state->getLatestReleaseDate());
        self::assertNull($state->getLatestCheckedAt());
        self::assertNull($state->getNotifiedBuildNumber());
    }

    public function testCurrentReturnsTheSameRowOnEveryCall(): void
    {
        $first = $this->repository->current();
        $this->em->clear();
        $second = $this->repository->current();

        self::assertSame(1, $this->repository->count([]));
        self::assertSame($first->getId(), $second->getId());
    }

    public function testSavedBuildsAreReadBackFromTheDatabase(): void
    {
        $state = $this->repository->current();
        $state
            ->setImportedBuildNumber(3213160)
            ->setImportedReleaseDate(new \DateTimeImmutable('2026-06-02T10:00:00Z'))
            ->setImportedAt(new \DateTimeImmutable('2026-06-03T08:15:00Z'))
            ->setLatestBuildNumber(3693310)
            ->setLatestReleaseDate(new \DateTimeImmutable('2026-11-12T11:07:00Z'))
            ->setLatestCheckedAt(new \DateTimeImmutable('2026-11-13T15:30:00Z'))
            ->setNotifiedBuildNumber(3693310);
        $this->repository->save($state);
        $this->em->clear();

        $reloaded = $this->repository->current();

        self::assertInstanceOf(SdeBuildState::class, $reloaded);
        self::assertSame(1, $this->repository->count([]));
        self::assertSame(3213160, $reloaded->getImportedBuildNumber());
        self::assertSame('2026-06-02T10:00:00+00:00', $reloaded->getImportedReleaseDate()?->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-06-03T08:15:00+00:00', $reloaded->getImportedAt()?->format(\DateTimeInterface::ATOM));
        self::assertSame(3693310, $reloaded->getLatestBuildNumber());
        self::assertSame('2026-11-12T11:07:00+00:00', $reloaded->getLatestReleaseDate()?->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-11-13T15:30:00+00:00', $reloaded->getLatestCheckedAt()?->format(\DateTimeInterface::ATOM));
        self::assertSame(3693310, $reloaded->getNotifiedBuildNumber());
    }
}
