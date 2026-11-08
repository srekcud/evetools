<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Mining;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Mining\FollowRefineryInput;
use App\Entity\MoonRefineryFollow;
use App\Entity\User;
use App\Repository\CorporationMiningExtractionRepository;
use App\State\Processor\Mining\FollowRefineryProcessor;
use App\Tests\Unit\Service\MoonChunk\StoresMoonRefineryFollows;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(FollowRefineryProcessor::class)]
#[Group('issue-274')]
#[AllowMockObjectsWithoutExpectations]
final class FollowRefineryProcessorTest extends TestCase
{
    use StoresMoonRefineryFollows;

    private const int CORPORATION_ID = 98_000_001;
    private const int ROCK_HOLLOW_ID = 1_035_000_000_101;
    private const int EMPTY_REFINERY_ID = 1_035_000_000_102;

    private ?User $currentUser = null;
    private MockClock $clock;
    private FollowRefineryProcessor $processor;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-05 19:00:00', 'UTC');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn (): ?User => $this->currentUser);
        $extractionRepository = $this->createStub(CorporationMiningExtractionRepository::class);
        $extractionRepository->method('existsForRefinery')->willReturnCallback(
            static fn (int $corporationId, int $structureId): bool => $corporationId === self::CORPORATION_ID && $structureId === self::ROCK_HOLLOW_ID,
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MoonRefineryFollow && !in_array($entity, $this->storedFollows, true)) {
                $this->storedFollows[] = $entity;
            }
        });
        $this->processor = new FollowRefineryProcessor($security, $extractionRepository, $this->followRepository(), $entityManager, $this->clock);
    }

    public function testFollowingTheSameRefineryTwiceKeepsASingleFollowCreatedAtTheFirstCall(): void
    {
        $this->currentUser = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');

        $first = $this->processor->process($this->following(self::ROCK_HOLLOW_ID), new Post());
        $this->clock->modify('+1 hour');
        $second = $this->processor->process($this->following(self::ROCK_HOLLOW_ID), new Post());

        self::assertCount(1, $this->storedFollows);
        self::assertSame(
            [[self::ROCK_HOLLOW_ID, self::CORPORATION_ID, '2026-11-05T19:00:00+00:00'], [self::ROCK_HOLLOW_ID, self::CORPORATION_ID, '2026-11-05T19:00:00+00:00']],
            [[$first->structureId, $first->corporationId, $first->createdAt], [$second->structureId, $second->corporationId, $second->createdAt]],
        );
    }

    public function testRefineryWithoutExtractionOfTheCorporationOrUnknownCorporationIsRefusedAndNothingIsStored(): void
    {
        $member = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $withoutCorporation = $this->followerInCorporation(null, 'Dorn Achar');

        self::assertSame(
            [BadRequestHttpException::class, BadRequestHttpException::class],
            [$this->exceptionClassOf($member, self::EMPTY_REFINERY_ID), $this->exceptionClassOf($withoutCorporation, self::ROCK_HOLLOW_ID)],
        );
        self::assertSame([], $this->storedFollows);
    }

    private function exceptionClassOf(User $user, int $structureId): ?string
    {
        $this->currentUser = $user;
        try {
            $this->processor->process($this->following($structureId), new Post());
        } catch (BadRequestHttpException $exception) {
            return $exception::class;
        }

        return null;
    }

    private function following(int $structureId): FollowRefineryInput
    {
        $input = new FollowRefineryInput();
        $input->structureId = $structureId;

        return $input;
    }
}
