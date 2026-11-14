<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\AdminStatusEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminStatusEvaluator::class)]
class AdminStatusEvaluatorTest extends TestCase
{
    private AdminStatusEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AdminStatusEvaluator();
    }

    public function testEverythingHealthyIsOkWithoutRules(): void
    {
        $status = $this->evaluator->evaluate([$this->entry('healthy', 'ok')], 0, 0, 0, null);

        $this->assertSame('ok', $status->level);
        $this->assertSame([], $status->rules);
    }

    public function testStaleScheduledTasksAreCountedAndDegrade(): void
    {
        $status = $this->evaluator->evaluate(
            [$this->entry('stale', 'ok'), $this->entry('stale', 'ok'), $this->entry('late', 'ok'), $this->entry('healthy', 'ok')],
            0,
            0,
            0,
            null,
        );

        $this->assertSame('degraded', $status->level);
        $this->assertSame(['scheduler_stale'], $this->ruleKeys($status));
        $this->assertSame(2, $status->rules[0]->value);
        $this->assertSame('degraded', $status->rules[0]->severity);
        $this->assertSame('scheduler-health', $status->rules[0]->section);
    }

    public function testScheduledTasksInErrorAreCountedAndDegrade(): void
    {
        $status = $this->evaluator->evaluate([$this->entry('healthy', 'error'), $this->entry('healthy', 'ok')], 0, 0, 0, null);

        $this->assertSame('degraded', $status->level);
        $this->assertSame(['scheduler_error'], $this->ruleKeys($status));
        $this->assertSame(1, $status->rules[0]->value);
    }

    public function testOnDemandEntriesNeverFireASchedulerRule(): void
    {
        $status = $this->evaluator->evaluate([$this->entry('on-demand', 'error', true), $this->entry('stale', 'error', true)], 0, 0, 0, null);

        $this->assertSame('ok', $status->level);
        $this->assertSame([], $status->rules);
    }

    public function testFailedMessagesDegrade(): void
    {
        $status = $this->evaluator->evaluate([], 0, 0, 3, null);

        $this->assertSame('degraded', $status->level);
        $this->assertSame(['failed_messages'], $this->ruleKeys($status));
        $this->assertSame(3, $status->rules[0]->value);
        $this->assertSame(AdminStatusEvaluator::FAILED_MESSAGES_THRESHOLD, $status->rules[0]->threshold);
        $this->assertSame('maintenance', $status->rules[0]->section);
    }

    /** @return iterable<string, array{int, string, ?int}> */
    public static function asyncDepthProvider(): iterable
    {
        yield 'at the degraded threshold' => [AdminStatusEvaluator::ASYNC_QUEUE_DEGRADED_DEPTH, 'ok', null];
        yield 'above the degraded threshold' => [AdminStatusEvaluator::ASYNC_QUEUE_DEGRADED_DEPTH + 1, 'degraded', AdminStatusEvaluator::ASYNC_QUEUE_DEGRADED_DEPTH];
        yield 'at the down threshold' => [AdminStatusEvaluator::ASYNC_QUEUE_DOWN_DEPTH, 'degraded', AdminStatusEvaluator::ASYNC_QUEUE_DEGRADED_DEPTH];
        yield 'above the down threshold' => [AdminStatusEvaluator::ASYNC_QUEUE_DOWN_DEPTH + 1, 'down', AdminStatusEvaluator::ASYNC_QUEUE_DOWN_DEPTH];
    }

    #[DataProvider('asyncDepthProvider')]
    public function testAsyncBacklogSeverityFollowsTheDepthThresholds(int $depth, string $expectedLevel, ?int $expectedThreshold): void
    {
        $status = $this->evaluator->evaluate([], 0, $depth, 0, null);

        $this->assertSame($expectedLevel, $status->level);
        if ($expectedThreshold === null) {
            $this->assertSame([], $status->rules);

            return;
        }
        $this->assertSame(['async_queue_backlog'], $this->ruleKeys($status));
        $this->assertSame($depth, $status->rules[0]->value);
        $this->assertSame($expectedThreshold, $status->rules[0]->threshold);
        $this->assertSame('maintenance', $status->rules[0]->section);
    }

    public function testUnknownAsyncDepthIsDownAndNeverReadAsZero(): void
    {
        $status = $this->evaluator->evaluate([], 0, null, 0, null);

        $this->assertSame('down', $status->level);
        $this->assertSame(['async_queue_unreachable'], $this->ruleKeys($status));
    }

    public function testUnknownFailedDepthDegradesWithoutTriggeringFailedMessages(): void
    {
        $status = $this->evaluator->evaluate([], 0, 0, null, null);

        $this->assertSame('degraded', $status->level);
        $this->assertSame(['failed_queue_unreachable'], $this->ruleKeys($status));
    }

    public function testUsersWithInvalidAuthDegrade(): void
    {
        $status = $this->evaluator->evaluate([], 4, 0, 0, null);

        $this->assertSame('degraded', $status->level);
        $this->assertSame(['tokens_invalid'], $this->ruleKeys($status));
        $this->assertSame(4, $status->rules[0]->value);
        $this->assertSame('kpis', $status->rules[0]->section);
    }

    public function testADownRuleWinsAndRulesKeepTheSchedulerQueueTokensOrder(): void
    {
        $status = $this->evaluator->evaluate([$this->entry('stale', 'ok')], 2, null, 5, null);

        $this->assertSame('down', $status->level);
        $this->assertSame(
            ['scheduler_stale', 'async_queue_unreachable', 'failed_messages', 'tokens_invalid'],
            $this->ruleKeys($status),
        );
    }

    /** @return iterable<string, array{?int, bool}> */
    public static function oldestRunningSecondsProvider(): iterable
    {
        yield 'no message running' => [null, false];
        yield 'just started' => [0, false];
        yield 'one second under the threshold' => [3599, false];
        yield 'at the threshold' => [AdminStatusEvaluator::IN_FLIGHT_MESSAGE_DEGRADED_SECONDS, false];
        yield 'one second over the threshold' => [3601, true];
        yield 'eleven hours as in the 2026-11-13 incident' => [39600, true];
    }

    #[Group('issue-838')]
    #[DataProvider('oldestRunningSecondsProvider')]
    public function testMessageRunningLongerThanTheThresholdDegradesTheStatus(?int $oldestRunningSeconds, bool $expectedRule): void
    {
        $status = $this->evaluator->evaluate([], 0, 0, 0, $oldestRunningSeconds);

        if (!$expectedRule) {
            $this->assertSame('ok', $status->level);
            $this->assertSame([], $status->rules);

            return;
        }
        $this->assertSame('degraded', $status->level);
        $this->assertSame(['message_unacked_too_long'], $this->ruleKeys($status));
        $this->assertSame($oldestRunningSeconds, $status->rules[0]->value);
        $this->assertSame(3600, $status->rules[0]->threshold);
        $this->assertSame('degraded', $status->rules[0]->severity);
        $this->assertSame('maintenance', $status->rules[0]->section);
    }

    #[Group('issue-838')]
    public function testMessageRunningTooLongDoesNotLiftAnUnreachableAsyncQueueAboveDown(): void
    {
        $status = $this->evaluator->evaluate([], 0, null, 0, 39600);

        $this->assertSame('down', $status->level);
        $this->assertEqualsCanonicalizing(['async_queue_unreachable', 'message_unacked_too_long'], $this->ruleKeys($status));
    }

    /** @return array<string, mixed> */
    private function entry(string $health, string $status, bool $onDemand = false): array
    {
        return ['type' => 'assets', 'health' => $health, 'status' => $status, 'on_demand' => $onDemand];
    }

    /** @return list<string> */
    private function ruleKeys(object $status): array
    {
        return array_map(static fn (object $rule): string => $rule->key, $status->rules);
    }
}
