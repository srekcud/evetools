<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Me;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Me\CreateBridgeAcknowledgementInput;
use App\ApiResource\Me\BridgeAcknowledgementResource;
use App\Entity\BridgeAcknowledgement;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use App\Repository\BridgeAcknowledgementRepository;
use App\State\Processor\Me\AcknowledgeBridgeTodoProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(AcknowledgeBridgeTodoProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-627')]
class AcknowledgeBridgeTodoProcessorTest extends TestCase
{
    private const int EXPIRED_EXTRACTORS_COUNT = 2;
    private const int REACKNOWLEDGED_EXPIRED_EXTRACTORS_COUNT = 5;
    private const int TRIGGERED_PRICE_ALERTS_COUNT = 1;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $em;
    private BridgeAcknowledgementRepository&Stub $acknowledgementRepository;
    private AcknowledgeBridgeTodoProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->acknowledgementRepository = $this->createStub(BridgeAcknowledgementRepository::class);

        $this->processor = new AcknowledgeBridgeTodoProcessor(
            $this->security,
            $this->acknowledgementRepository,
            $this->em,
        );
    }

    public function testFirstAcknowledgementStoresTheCountForTheCurrentUser(): void
    {
        $user = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->acknowledgementRepository->method('findByUserAndItemKey')->willReturn(null);

        $this->em->expects($this->once())->method('persist')->with($this->callback(
            static fn (BridgeAcknowledgement $stored): bool => $stored->getUser() === $user
                && $stored->getItemKey() === BridgeTodoKey::ExpiredExtractors
                && $stored->getAcknowledgedCount() === self::EXPIRED_EXTRACTORS_COUNT,
        ));
        $this->em->expects($this->once())->method('flush');

        $result = $this->processor->process(
            $this->inputFor(BridgeTodoKey::ExpiredExtractors, self::EXPIRED_EXTRACTORS_COUNT),
            new Post(),
        );

        $this->assertInstanceOf(BridgeAcknowledgementResource::class, $result);
        $this->assertSame('expiredExtractors', $result->itemKey);
        $this->assertSame(self::EXPIRED_EXTRACTORS_COUNT, $result->acknowledgedCount);
    }

    public function testAcknowledgingAnAlreadyAcknowledgedKeyReplacesTheCountWithoutCreatingADuplicate(): void
    {
        $user = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($user);
        $existing = (new BridgeAcknowledgement())
            ->setUser($user)
            ->setItemKey(BridgeTodoKey::ExpiredExtractors)
            ->setAcknowledgedCount(self::EXPIRED_EXTRACTORS_COUNT);
        $this->acknowledgementRepository->method('findByUserAndItemKey')->willReturnCallback(
            static fn (User $owner, BridgeTodoKey $requested): ?BridgeAcknowledgement => $owner === $user && $requested === BridgeTodoKey::ExpiredExtractors ? $existing : null,
        );

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $this->processor->process(
            $this->inputFor(BridgeTodoKey::ExpiredExtractors, self::REACKNOWLEDGED_EXPIRED_EXTRACTORS_COUNT),
            new Post(),
        );

        $this->assertSame(self::REACKNOWLEDGED_EXPIRED_EXTRACTORS_COUNT, $existing->getAcknowledgedCount());
        $this->assertSame(self::REACKNOWLEDGED_EXPIRED_EXTRACTORS_COUNT, $result->acknowledgedCount);
    }

    public function testAcknowledgingAnotherKeyDoesNotTouchTheExistingAcknowledgement(): void
    {
        $user = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($user);
        $existing = (new BridgeAcknowledgement())
            ->setUser($user)
            ->setItemKey(BridgeTodoKey::ExpiredExtractors)
            ->setAcknowledgedCount(self::EXPIRED_EXTRACTORS_COUNT);
        $this->acknowledgementRepository->method('findByUserAndItemKey')->willReturnCallback(
            static fn (User $owner, BridgeTodoKey $requested): ?BridgeAcknowledgement => $requested === BridgeTodoKey::ExpiredExtractors ? $existing : null,
        );

        $this->em->expects($this->once())->method('persist')->with($this->callback(
            static fn (BridgeAcknowledgement $stored): bool => $stored->getItemKey() === BridgeTodoKey::TriggeredPriceAlerts
                && $stored->getAcknowledgedCount() === self::TRIGGERED_PRICE_ALERTS_COUNT,
        ));

        $result = $this->processor->process(
            $this->inputFor(BridgeTodoKey::TriggeredPriceAlerts, self::TRIGGERED_PRICE_ALERTS_COUNT),
            new Post(),
        );

        $this->assertSame(self::EXPIRED_EXTRACTORS_COUNT, $existing->getAcknowledgedCount());
        $this->assertSame('triggeredPriceAlerts', $result->itemKey);
        $this->assertSame(self::TRIGGERED_PRICE_ALERTS_COUNT, $result->acknowledgedCount);
    }

    public function testThrowsUnauthorizedWhenNoUserAndStoresNothing(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor->process($this->inputFor(BridgeTodoKey::ExpiredExtractors, self::EXPIRED_EXTRACTORS_COUNT), new Post());
    }

    private function inputFor(BridgeTodoKey $itemKey, int $count): CreateBridgeAcknowledgementInput
    {
        $input = new CreateBridgeAcknowledgementInput();
        $input->itemKey = $itemKey->value;
        $input->count = $count;

        return $input;
    }
}
