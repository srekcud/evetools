<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\State\Provider\Market\MarketAlertResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MarketAlertResourceMapper::class)]
#[Group('issue-632')]
final class MarketAlertResourceMapperTest extends TestCase
{
    private const string ALERT_ID = '0192b1c4-3c4d-7e5f-8a6b-7c8d9e0f1a23';

    public function testMapsEveryFieldOfAnActiveAlertAndTheGivenCurrentPrice(): void
    {
        $alert = $this->alert();

        $resource = MarketAlertResourceMapper::toResource($alert, 8.75);

        $this->assertSame(
            [self::ALERT_ID, 35, 'Pyerite', 'below', 8.5, 'jita_buy', 'active', 8.75, null, null, $alert->getCreatedAt()->format('c')],
            [
                $resource->id,
                $resource->typeId,
                $resource->typeName,
                $resource->direction,
                $resource->threshold,
                $resource->priceSource,
                $resource->status,
                $resource->currentPrice,
                $resource->triggeredAt,
                $resource->triggeredPrice,
                $resource->createdAt,
            ],
        );
    }

    public function testMapsTheTriggerDateAndPriceOfATriggeredAlert(): void
    {
        $alert = $this->alert()->trigger(7.25);

        $resource = MarketAlertResourceMapper::toResource($alert, 7.5);

        $this->assertSame('triggered', $resource->status);
        $this->assertSame(7.25, $resource->triggeredPrice);
        $this->assertNotNull($alert->getTriggeredAt());
        $this->assertSame($alert->getTriggeredAt()->format('c'), $resource->triggeredAt);
    }

    public function testKeepsAnUnknownCurrentPriceAsNull(): void
    {
        $resource = MarketAlertResourceMapper::toResource($this->alert(), null);

        $this->assertNull($resource->currentPrice);
    }

    public function testMapsTheStructureIdAndTheGivenStructureNameOfAStructureAlert(): void
    {
        $alert = $this->alert()->setPriceSource(AlertPriceSource::StructureSell)->setStructureId(1035466617946);

        $resource = MarketAlertResourceMapper::toResource($alert, 8.75, 'Perimeter - Tranquility Trading Tower');

        $this->assertSame(1035466617946, $resource->structureId);
        $this->assertSame('Perimeter - Tranquility Trading Tower', $resource->structureName);
    }

    public function testKeepsTheStructureNameNullWhenTheStructureIsNoLongerKnown(): void
    {
        $alert = $this->alert()->setPriceSource(AlertPriceSource::StructureBuy)->setStructureId(1028858195912);

        $resource = MarketAlertResourceMapper::toResource($alert, null);

        $this->assertSame(1028858195912, $resource->structureId);
        $this->assertNull($resource->structureName);
    }

    public function testMapsNoStructureForAJitaAlert(): void
    {
        $resource = MarketAlertResourceMapper::toResource($this->alert(), 8.75);

        $this->assertNull($resource->structureId);
        $this->assertNull($resource->structureName);
    }

    private function alert(): MarketPriceAlert
    {
        $alert = (new MarketPriceAlert())
            ->setUser($this->createStub(User::class))
            ->setTypeId(35)
            ->setTypeName('Pyerite')
            ->setDirection(AlertDirection::Below)
            ->setThreshold(8.5)
            ->setPriceSource(AlertPriceSource::JitaBuy);
        (new \ReflectionProperty(MarketPriceAlert::class, 'id'))->setValue($alert, Uuid::fromString(self::ALERT_ID));

        return $alert;
    }
}
