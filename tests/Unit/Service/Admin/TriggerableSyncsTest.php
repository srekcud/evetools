<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Message\CheckSdeBuild;
use App\Message\SyncCostIndices;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerStructureMarketSync;
use App\Service\Admin\SyncTracker;
use App\Service\Admin\TriggerableSyncs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(TriggerableSyncs::class)]
class TriggerableSyncsTest extends TestCase
{
    private const SYNCHRONOUS_TYPE = 'market-alerts';

    /** @return iterable<string, array{string}> */
    public static function trackedTypes(): iterable
    {
        $types = array_merge(array_keys(SyncTracker::EXPECTED_INTERVALS), SyncTracker::ON_DEMAND);

        foreach ($types as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('trackedTypes')]
    public function testEveryTrackedTypeIsTriggerable(string $type): void
    {
        $this->assertTrue(TriggerableSyncs::isTriggerable($type));
    }

    #[DataProvider('trackedTypes')]
    public function testEveryTrackedTypeHasAMessageToDispatchExceptTheSynchronousOne(string $type): void
    {
        $messages = TriggerableSyncs::messagesFor($type);

        if ($type === self::SYNCHRONOUS_TYPE) {
            $this->assertSame([], $messages);

            return;
        }

        $this->assertNotEmpty($messages);
    }

    public function testListedTypesAreExactlyTheTrackedTypes(): void
    {
        $tracked = array_merge(array_keys(SyncTracker::EXPECTED_INTERVALS), SyncTracker::ON_DEMAND);

        $this->assertEqualsCanonicalizing($tracked, TriggerableSyncs::types());
    }

    public function testCostIndicesDispatchesItsOwnMessage(): void
    {
        $this->assertEquals([new SyncCostIndices()], TriggerableSyncs::messagesFor('cost-indices'));
    }

    public function testEachMarketTypeDispatchesOnlyItsOwnMessage(): void
    {
        $this->assertEquals([new TriggerJitaMarketSync()], TriggerableSyncs::messagesFor('market-jita'));
        $this->assertEquals([new TriggerStructureMarketSync()], TriggerableSyncs::messagesFor('market-structure'));
    }

    public function testSdeBuildDispatchesItsOwnMessage(): void
    {
        $this->assertEquals([new CheckSdeBuild()], TriggerableSyncs::messagesFor('sde-build'));
    }

    #[Group('issue-916')]
    public function testWalletDispatchesItsMessageForEveryCharacterWhateverTheirNextReadAt(): void
    {
        $this->assertEquals([new SyncWalletTransactions(dueOnly: false)], TriggerableSyncs::messagesFor('wallet'));
    }

    public function testAnUnknownTypeIsNotTriggerable(): void
    {
        $this->assertFalse(TriggerableSyncs::isTriggerable('does-not-exist'));
        $this->assertSame([], TriggerableSyncs::messagesFor('does-not-exist'));
    }
}
