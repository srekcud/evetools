<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\Delete;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Repository\MarketPriceAlertRepository;
use App\State\Provider\Market\MarketAlertDeleteProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MarketAlertDeleteProvider::class)]
class MarketAlertDeleteProviderTest extends TestCase
{
    public function testExposesTriggeredAtAndTriggeredPriceOfTriggeredAlert(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $alert = (new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId(35)
            ->setTypeName('Pyerite')
            ->setDirection(AlertDirection::Below)
            ->setThreshold(8.5)
            ->setPriceSource(AlertPriceSource::JitaBuy)
            ->trigger(7.25);
        $alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $alertRepository->method('find')->willReturn($alert);

        $resource = (new MarketAlertDeleteProvider($security, $alertRepository))
            ->provide(new Delete(), ['id' => Uuid::v4()->toRfc4122()]);

        $this->assertSame(7.25, $resource->triggeredPrice);
        $this->assertSame($alert->getTriggeredAt()?->format('c'), $resource->triggeredAt);
        $this->assertNotNull($resource->triggeredAt);
        $this->assertSame('triggered', $resource->status);
    }
}
