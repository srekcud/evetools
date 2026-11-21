<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectJobMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectJobMatch::class)]
#[Group('issue-1245')]
final class IndustryProjectJobMatchHasNoOutputTest extends TestCase
{
    #[DataProvider('statusesWithoutOutput')]
    public function testAMatchWhoseJobWasCancelledOrRevertedHasNoOutput(string $status): void
    {
        $match = (new IndustryProjectJobMatch())->setStatus($status);

        self::assertTrue($match->hasNoOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusesWithoutOutput(): iterable
    {
        yield 'cancelled' => ['cancelled'];
        yield 'reverted' => ['reverted'];
    }

    #[DataProvider('statusesWithOutput')]
    public function testAMatchWhoseJobIsRunningOrFinishedHasAnOutput(string $status): void
    {
        $match = (new IndustryProjectJobMatch())->setStatus($status);

        self::assertFalse($match->hasNoOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusesWithOutput(): iterable
    {
        yield 'active' => ['active'];
        yield 'ready' => ['ready'];
        yield 'delivered' => ['delivered'];
        yield 'paused' => ['paused'];
    }
}
