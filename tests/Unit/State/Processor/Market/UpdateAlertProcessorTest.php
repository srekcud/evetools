<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Market;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Market\UpdateAlertInput;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\AlertStatus;
use App\Repository\MarketPriceAlertRepository;
use App\Service\JitaMarketService;
use App\Service\MarketAlertPriceResolver;
use App\Service\StructureMarketService;
use App\State\Processor\Market\UpdateAlertProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateAlertProcessor::class)]
#[Group('issue-537')]
final class UpdateAlertProcessorTest extends TestCase
{
    private const string USER_ID = '0192b1c4-1a2b-7c3d-8e4f-5a6b7c8d9e01';
    private const string OTHER_USER_ID = '0192b1c4-2b3c-7d4e-9f5a-6b7c8d9e0f12';
    private const string ALERT_ID = '0192b1c4-3c4d-7e5f-8a6b-7c8d9e0f1a23';

    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;

    private const string TRIGGERED_AT = '2026-11-09T18:30:00+00:00';

    public function testRearmsATriggeredAlertWithTheNewThresholdAndClearsItsTriggeredAt(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->triggeredAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $resource = $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: 8.0), new Patch(), ['id' => self::ALERT_ID]);

        $this->assertInstanceOf(MarketAlertResource::class, $resource);
        $this->assertSame(AlertStatus::Active, $alert->getStatus());
        $this->assertNull($alert->getTriggeredAt());
        $this->assertSame(8.0, $alert->getThreshold());
        $this->assertSame(AlertDirection::Below, $alert->getDirection());
        $this->assertSame([self::PYERITE, 'Pyerite', AlertPriceSource::JitaBuy], [$alert->getTypeId(), $alert->getTypeName(), $alert->getPriceSource()]);
        $this->assertSame(['active', 8.0, 'below', 'jita_buy', self::PYERITE], [$resource->status, $resource->threshold, $resource->direction, $resource->priceSource, $resource->typeId]);
        $this->assertNull($resource->triggeredAt);
    }

    #[Group('issue-626')]
    public function testManualRearmClearsTheLastTriggerPriceAlongWithItsDate(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->triggeredAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy)
            ->setTriggeredPrice(8.6875);
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $resource = $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: 8.0), new Patch(), ['id' => self::ALERT_ID]);

        $this->assertNull($alert->getTriggeredPrice());
        $this->assertNull($resource->triggeredPrice);
    }

    #[Group('issue-632')]
    public function testReturnsTheLiveCurrentPriceOfTheRearmedAlert(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->triggeredAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy);
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $resource = $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: 8.0), new Patch(), ['id' => self::ALERT_ID]);

        $this->assertSame(9.0, $resource->currentPrice);
        $this->assertSame('active', $resource->status);
    }

    public function testRearmsATriggeredAlertSavedWithAnEmptyBodyAndKeepsItsThresholdAndDirection(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->triggeredAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Above, 61.5, AlertPriceSource::JitaSell);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $this->processorFor($user, $alert, $entityManager)->process($this->input(), new Patch(), ['id' => self::ALERT_ID]);

        $this->assertSame(AlertStatus::Active, $alert->getStatus());
        $this->assertNull($alert->getTriggeredAt());
        $this->assertSame(61.5, $alert->getThreshold());
        $this->assertSame(AlertDirection::Above, $alert->getDirection());
    }

    public function testRearmsATriggeredAlertSavedWithTheSameThreshold(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->triggeredAlert($user, self::ISOGEN, 'Isogen', AlertDirection::Above, 47.0, AlertPriceSource::StructureBuy);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: 47.0, direction: 'above'), new Patch(), ['id' => self::ALERT_ID]);

        $this->assertSame(AlertStatus::Active, $alert->getStatus());
        $this->assertNull($alert->getTriggeredAt());
        $this->assertSame(47.0, $alert->getThreshold());
    }

    public function testKeepsAnActiveAlertActiveWhileUpdatingItsThreshold(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->activeAlert($user, self::ISOGEN, 'Isogen', AlertDirection::Above, 47.0, AlertPriceSource::JitaSell);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $resource = $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: 12.0), new Patch(), ['id' => self::ALERT_ID]);

        $this->assertSame(AlertStatus::Active, $alert->getStatus());
        $this->assertNull($alert->getTriggeredAt());
        $this->assertSame(12.0, $alert->getThreshold());
        $this->assertSame(AlertDirection::Above, $alert->getDirection());
        $this->assertSame(['active', 12.0], [$resource->status, $resource->threshold]);
    }

    public function testUpdatesTheDirectionOfAnAlert(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->activeAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy);

        $resource = $this->processorFor($user, $alert, $this->createStub(EntityManagerInterface::class))->process($this->input(direction: 'above'), new Patch(), ['id' => self::ALERT_ID]);

        $this->assertSame(AlertDirection::Above, $alert->getDirection());
        $this->assertSame(9.125, $alert->getThreshold());
        $this->assertSame('above', $resource->direction);
    }

    public function testAcceptsTheSmallestPositiveThreshold(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->activeAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Below, 61.5, AlertPriceSource::JitaSell);

        $this->processorFor($user, $alert, $this->createStub(EntityManagerInterface::class))->process($this->input(threshold: 0.01), new Patch(), ['id' => self::ALERT_ID]);

        $this->assertSame(0.01, $alert->getThreshold());
    }

    public function testRejectsAZeroThresholdWithTheCreationMessageAndLeavesATriggeredAlertUntouched(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->triggeredAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: 0.0), new Patch(), ['id' => self::ALERT_ID]);
            $this->fail('A zero threshold must be rejected');
        } catch (BadRequestHttpException $exception) {
            $this->assertSame('Threshold must be positive', $exception->getMessage());
        }

        $this->assertSame(AlertStatus::Triggered, $alert->getStatus());
        $this->assertNotNull($alert->getTriggeredAt());
        $this->assertSame(9.125, $alert->getThreshold());
    }

    public function testRejectsANegativeThresholdWithTheCreationMessageAndLeavesAnActiveAlertUntouched(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->activeAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Above, 61.5, AlertPriceSource::JitaSell);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: -1.0), new Patch(), ['id' => self::ALERT_ID]);
            $this->fail('A negative threshold must be rejected');
        } catch (BadRequestHttpException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
            $this->assertSame('Threshold must be positive', $exception->getMessage());
        }

        $this->assertSame([AlertStatus::Active, 61.5], [$alert->getStatus(), $alert->getThreshold()]);
    }

    public function testRejectsAnUnknownDirectionWithTheCreationMessageAndLeavesATriggeredAlertUntouched(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->triggeredAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Above, 61.5, AlertPriceSource::JitaSell);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: 70.0, direction: 'sideways'), new Patch(), ['id' => self::ALERT_ID]);
            $this->fail('An unknown direction must be rejected');
        } catch (BadRequestHttpException $exception) {
            $this->assertSame('Invalid direction. Must be "above" or "below"', $exception->getMessage());
        }

        $this->assertSame([AlertStatus::Triggered, 61.5, AlertDirection::Above], [$alert->getStatus(), $alert->getThreshold(), $alert->getDirection()]);
    }

    public function testRefusesToUpdateTheAlertOfAnotherUser(): void
    {
        $owner = $this->createUser(self::OTHER_USER_ID);
        $alert = $this->triggeredAlert($owner, self::ISOGEN, 'Isogen', AlertDirection::Above, 47.0, AlertPriceSource::JitaSell);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->processorFor($this->createUser(self::USER_ID), $alert, $entityManager)->process($this->input(threshold: 8.0), new Patch(), ['id' => self::ALERT_ID]);
            $this->fail('The alert of another user must not be updated');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([AlertStatus::Triggered, 47.0], [$alert->getStatus(), $alert->getThreshold()]);
    }

    public function testReportsAnAbsentAlertAsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->processorFor($this->createUser(self::USER_ID), null, $this->createStub(EntityManagerInterface::class))->process($this->input(threshold: 8.0), new Patch(), ['id' => self::ALERT_ID]);
    }

    public function testRefusesToUpdateAnExpiredAlertWithAConflict(): void
    {
        $user = $this->createUser(self::USER_ID);
        $alert = $this->activeAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy)->setStatus(AlertStatus::Expired);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->processorFor($user, $alert, $entityManager)->process($this->input(threshold: 8.0), new Patch(), ['id' => self::ALERT_ID]);
            $this->fail('An expired alert must not be updated');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([AlertStatus::Expired, 9.125], [$alert->getStatus(), $alert->getThreshold()]);
    }

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $processor = new UpdateAlertProcessor($security, $this->createStub(MarketPriceAlertRepository::class), $this->createStub(EntityManagerInterface::class), $this->priceResolver());

        $this->expectException(UnauthorizedHttpException::class);

        $processor->process($this->input(threshold: 8.0), new Patch(), ['id' => self::ALERT_ID]);
    }

    private function processorFor(User $user, ?MarketPriceAlert $alert, EntityManagerInterface $entityManager): UpdateAlertProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $repository = $this->createStub(MarketPriceAlertRepository::class);
        $repository->method('find')->willReturn($alert);

        return new UpdateAlertProcessor($security, $repository, $entityManager, $this->priceResolver());
    }

    private function priceResolver(): MarketAlertPriceResolver
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturn([self::PYERITE => 12.0, self::MEXALLON => 55.25, self::ISOGEN => 8700000.0]);
        $jitaMarketService->method('getBuyPricesWithFallback')->willReturn([self::PYERITE => 9.0, self::MEXALLON => 50.0, self::ISOGEN => 8000000.0]);

        return new MarketAlertPriceResolver($jitaMarketService, $this->createStub(StructureMarketService::class));
    }

    private function input(?float $threshold = null, ?string $direction = null): UpdateAlertInput
    {
        $input = new UpdateAlertInput();
        $input->threshold = $threshold;
        $input->direction = $direction;

        return $input;
    }

    private function createUser(string $userId): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($userId));

        return $user;
    }

    private function activeAlert(
        User $user,
        int $typeId,
        string $typeName,
        AlertDirection $direction,
        float $threshold,
        AlertPriceSource $priceSource,
    ): MarketPriceAlert {
        $alert = (new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setDirection($direction)
            ->setThreshold($threshold)
            ->setPriceSource($priceSource);
        (new \ReflectionProperty(MarketPriceAlert::class, 'id'))->setValue($alert, Uuid::fromString(self::ALERT_ID));

        return $alert;
    }

    private function triggeredAlert(
        User $user,
        int $typeId,
        string $typeName,
        AlertDirection $direction,
        float $threshold,
        AlertPriceSource $priceSource,
    ): MarketPriceAlert {
        return $this->activeAlert($user, $typeId, $typeName, $direction, $threshold, $priceSource)
            ->setStatus(AlertStatus::Triggered)
            ->setTriggeredAt(new \DateTimeImmutable(self::TRIGGERED_AT));
    }
}
