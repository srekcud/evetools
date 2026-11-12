<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Service\ESI\EsiErrorBudget;
use App\Service\ESI\EsiErrorBudgetBroadcaster;
use App\Tests\Unit\Service\Mercure\RecordsAdminHealthUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EsiErrorBudget::class)]
#[Group('issue-813')]
final class EsiErrorBudgetBroadcastTest extends TestCase
{
    use RecordsAdminHealthUpdates;

    private const string START = '2026-11-13 10:30:00';

    private MockClock $clock;
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::START . ' UTC');
        $this->pool = new ArrayAdapter();
    }

    public function testALimitedResponseSignalsTheEsiErrorBudgetScopeOnce(): void
    {
        $budget = $this->budgetWithBroadcaster();

        $budget->recordLimitedResponse(429, '/characters/90000001/assets/');
        $budget->recordLimitedResponse(429, '/characters/90000002/assets/');

        self::assertSame(['esi-error-budget'], $this->publishedScopes());
    }

    public function testALimitedResponseIsStillCountedWhenTheBroadcasterIsPresent(): void
    {
        $budget = $this->budgetWithBroadcaster();

        $budget->recordLimitedResponse(420, '/status/');

        self::assertSame(1, $budget->snapshot()['limited420']);
    }

    public function testObservingTheBudgetSignalsItsLevelOnTheFirstObservationAndOnEveryLevelChange(): void
    {
        $budget = $this->budgetWithBroadcaster();

        $budget->observe(30, 60);
        self::assertSame(['esi-error-budget'], $this->publishedScopes());

        $budget->observe(25, 60);
        self::assertSame(['esi-error-budget'], $this->publishedScopes());

        $budget->observe(15, 60);
        self::assertSame(['esi-error-budget', 'esi-error-budget'], $this->publishedScopes());
    }

    public function testObservingAndLimitedResponsesWorkWithoutBroadcaster(): void
    {
        $budget = new EsiErrorBudget($this->pool, clock: $this->clock);

        $budget->observe(12, 45);
        $budget->recordLimitedResponse(429, '/status/');

        $snapshot = $budget->snapshot();
        self::assertSame(12, $snapshot['remain']);
        self::assertSame('low', $snapshot['level']);
        self::assertSame(1, $snapshot['limited429']);
        self::assertSame([], $this->publishedScopes());
    }

    private function budgetWithBroadcaster(): EsiErrorBudget
    {
        $broadcaster = new EsiErrorBudgetBroadcaster(
            $this->pool,
            $this->adminHealthPublisher(),
            $this->warningCollector(),
            $this->clock,
        );

        return new EsiErrorBudget($this->pool, clock: $this->clock, broadcaster: $broadcaster);
    }
}
