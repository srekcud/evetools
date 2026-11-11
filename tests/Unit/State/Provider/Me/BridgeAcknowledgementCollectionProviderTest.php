<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Me;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\BridgeAcknowledgement;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use App\Repository\BridgeAcknowledgementRepository;
use App\State\Provider\Me\BridgeAcknowledgementCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(BridgeAcknowledgementCollectionProvider::class)]
#[Group('issue-627')]
class BridgeAcknowledgementCollectionProviderTest extends TestCase
{
    private const int EXPIRED_EXTRACTORS_COUNT = 2;
    private const int TRIGGERED_PRICE_ALERTS_COUNT = 1;

    public function testListsTheAcknowledgementsOfTheCurrentUserOnly(): void
    {
        $user = $this->createStub(User::class);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $own = [
            $this->acknowledgementOf($user, BridgeTodoKey::ExpiredExtractors, self::EXPIRED_EXTRACTORS_COUNT),
            $this->acknowledgementOf($user, BridgeTodoKey::TriggeredPriceAlerts, self::TRIGGERED_PRICE_ALERTS_COUNT),
        ];
        $repository = $this->createStub(BridgeAcknowledgementRepository::class);
        $repository->method('findByUser')->willReturnCallback(
            static fn (User $owner): array => $owner === $user ? $own : [],
        );

        $resources = (new BridgeAcknowledgementCollectionProvider($security, $repository))->provide(new GetCollection());

        $this->assertSame(
            [['expiredExtractors', self::EXPIRED_EXTRACTORS_COUNT], ['triggeredPriceAlerts', self::TRIGGERED_PRICE_ALERTS_COUNT]],
            array_map(static fn ($resource): array => [$resource->itemKey, $resource->acknowledgedCount], $resources),
        );
    }

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        (new BridgeAcknowledgementCollectionProvider($security, $this->createStub(BridgeAcknowledgementRepository::class)))->provide(new GetCollection());
    }

    private function acknowledgementOf(User $user, BridgeTodoKey $itemKey, int $count): BridgeAcknowledgement
    {
        return (new BridgeAcknowledgement())->setUser($user)->setItemKey($itemKey)->setAcknowledgedCount($count);
    }
}
