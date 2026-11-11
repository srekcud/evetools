<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Me;

use ApiPlatform\Metadata\Delete;
use App\Entity\BridgeAcknowledgement;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use App\Repository\BridgeAcknowledgementRepository;
use App\State\Processor\Me\RemoveBridgeAcknowledgementProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(RemoveBridgeAcknowledgementProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-627')]
class RemoveBridgeAcknowledgementProcessorTest extends TestCase
{
    private const int EXPIRED_EXTRACTORS_COUNT = 2;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $em;
    private BridgeAcknowledgementRepository&Stub $acknowledgementRepository;
    private RemoveBridgeAcknowledgementProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->acknowledgementRepository = $this->createStub(BridgeAcknowledgementRepository::class);

        $this->processor = new RemoveBridgeAcknowledgementProcessor(
            $this->security,
            $this->acknowledgementRepository,
            $this->em,
        );
    }

    public function testRemovesTheAcknowledgementOfTheCurrentUser(): void
    {
        $user = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($user);
        $acknowledgement = (new BridgeAcknowledgement())
            ->setUser($user)
            ->setItemKey(BridgeTodoKey::ExpiredExtractors)
            ->setAcknowledgedCount(self::EXPIRED_EXTRACTORS_COUNT);
        $this->acknowledgementRepository->method('findByUserAndItemKey')->willReturnCallback(
            static fn (User $owner, BridgeTodoKey $requested): ?BridgeAcknowledgement => $owner === $user && $requested === BridgeTodoKey::ExpiredExtractors ? $acknowledgement : null,
        );

        $this->em->expects($this->once())->method('remove')->with($acknowledgement);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process(null, new Delete(), ['itemKey' => 'expiredExtractors']);
    }

    public function testThrowsNotFoundAndRemovesNothingWhenTheCurrentUserHasNoAcknowledgementForTheKey(): void
    {
        $this->security->method('getUser')->willReturn($this->createStub(User::class));
        $this->acknowledgementRepository->method('findByUserAndItemKey')->willReturn(null);

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);

        $this->processor->process(null, new Delete(), ['itemKey' => 'expiredExtractors']);
    }
}
