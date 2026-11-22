<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Transfer;

use App\Service\Industry\Project\Transfer\TransferLeg;
use App\Service\Industry\Project\Transfer\TransferRoute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransferRoute::class)]
#[Group('issue-1295')]
final class TransferRouteTest extends TestCase
{
    private const int JITA = 30_000_142;
    private const int HUB = 30_000_772;
    private const int SYSTEM_S1 = 30_000_144;
    private const int SYSTEM_S2 = 30_002_187;

    public function testJitaToASystemOutsideTheHubIsACourierToTheHubThenAJumpBridgeToTheSystem(): void
    {
        self::assertSame(
            [['courier', self::JITA, self::HUB], ['jump_bridge', self::HUB, self::SYSTEM_S1]],
            $this->rowsOf(TransferRoute::legs(self::JITA, self::SYSTEM_S1, self::HUB)),
        );
    }

    public function testJitaToTheHubIsOneCourierLeg(): void
    {
        self::assertSame([['courier', self::JITA, self::HUB]], $this->rowsOf(TransferRoute::legs(self::JITA, self::HUB, self::HUB)));
    }

    public function testASystemOutsideTheHubToJitaIsAJumpBridgeToTheHubThenACourierToJita(): void
    {
        self::assertSame(
            [['jump_bridge', self::SYSTEM_S1, self::HUB], ['courier', self::HUB, self::JITA]],
            $this->rowsOf(TransferRoute::legs(self::SYSTEM_S1, self::JITA, self::HUB)),
        );
    }

    public function testTheHubToJitaIsOneCourierLeg(): void
    {
        self::assertSame([['courier', self::HUB, self::JITA]], $this->rowsOf(TransferRoute::legs(self::HUB, self::JITA, self::HUB)));
    }

    public function testTwoSystemsOtherThanJitaAreOneJumpBridgeLeg(): void
    {
        self::assertSame([['jump_bridge', self::SYSTEM_S1, self::SYSTEM_S2]], $this->rowsOf(TransferRoute::legs(self::SYSTEM_S1, self::SYSTEM_S2, self::HUB)));
    }

    public function testTheSameSystemIsOnePilotLeg(): void
    {
        self::assertSame([['pilot', self::SYSTEM_S1, self::SYSTEM_S1]], $this->rowsOf(TransferRoute::legs(self::SYSTEM_S1, self::SYSTEM_S1, self::HUB)));
    }

    public function testJitaToJitaHasNoLegAtAll(): void
    {
        self::assertSame([], TransferRoute::legs(self::JITA, self::JITA, self::HUB));
    }

    private function rowsOf(array $legs): array
    {
        return array_map(static fn (TransferLeg $leg): array => [$leg->carrierKind->value, $leg->fromSolarSystemId, $leg->toSolarSystemId], $legs);
    }
}
