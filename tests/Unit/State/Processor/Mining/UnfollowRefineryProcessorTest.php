<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Mining;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Mining\FollowedRefineryResource;
use App\State\Processor\Mining\UnfollowRefineryProcessor;
use App\Tests\Unit\Service\MoonChunk\StoresMoonRefineryFollows;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UnfollowRefineryProcessor::class)]
#[Group('issue-274')]
#[AllowMockObjectsWithoutExpectations]
final class UnfollowRefineryProcessorTest extends TestCase
{
    use StoresMoonRefineryFollows;

    private const int CORPORATION_ID = 98_000_001;
    private const int ROCK_HOLLOW_ID = 1_035_000_000_101;
    private const int DEEP_CORE_ID = 1_035_000_000_102;

    private EntityManagerInterface&MockObject $entityManager;

    public function testUnfollowingARefineryRemovesOnlyTheFollowOfTheUserOnThatRefinery(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $sora = $this->followerInCorporation(self::CORPORATION_ID, 'Sora Vantel');
        $ilyraRockHollow = $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, '2026-11-04T12:00:00+00:00');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::DEEP_CORE_ID, '2026-11-04T12:00:00+00:00');
        $this->storeFollow($sora, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, '2026-11-04T13:00:00+00:00');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($ilyra);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->expects($this->once())->method('remove')->with($ilyraRockHollow);
        $this->entityManager->expects($this->once())->method('flush');
        $resource = new FollowedRefineryResource();
        $resource->structureId = self::ROCK_HOLLOW_ID;

        $result = (new UnfollowRefineryProcessor($security, $this->followRepository(), $this->entityManager))
            ->process($resource, new Delete(), ['structureId' => self::ROCK_HOLLOW_ID]);

        self::assertNull($result);
    }
}
