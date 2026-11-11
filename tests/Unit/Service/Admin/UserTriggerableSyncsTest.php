<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Message\SyncUserBlueprints;
use App\Message\SyncUserCharacterOrders;
use App\Message\SyncUserIndustryJobs;
use App\Message\SyncUserMiningData;
use App\Message\SyncUserPveData;
use App\Service\Admin\UserTriggerableSyncs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserTriggerableSyncs::class)]
#[Group('issue-647')]
final class UserTriggerableSyncsTest extends TestCase
{
    private const USER_ID = '0192f0c4-6d2e-7a1b-9c3d-4e5f6a7b8c9d';

    public function testTheTypesAreListedInDisplayOrder(): void
    {
        self::assertSame(
            ['industry', 'blueprints', 'character-orders', 'pve', 'mining', 'assets', 'wallet', 'planetary'],
            UserTriggerableSyncs::TYPES,
        );
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function triggerableTypes(): iterable
    {
        yield 'industry' => ['industry', SyncUserIndustryJobs::class];
        yield 'blueprints' => ['blueprints', SyncUserBlueprints::class];
        yield 'character-orders' => ['character-orders', SyncUserCharacterOrders::class];
        yield 'pve' => ['pve', SyncUserPveData::class];
        yield 'mining' => ['mining', SyncUserMiningData::class];
    }

    /**
     * @param class-string $messageClass
     */
    #[DataProvider('triggerableTypes')]
    public function testATriggerableTypeBuildsItsUserMessage(string $type, string $messageClass): void
    {
        self::assertTrue(UserTriggerableSyncs::isTriggerable($type));

        $message = UserTriggerableSyncs::messageFor($type, self::USER_ID);

        self::assertInstanceOf($messageClass, $message);
        self::assertSame(self::USER_ID, $message->userId);
    }

    #[DataProvider('displayOnlyTypes')]
    public function testADisplayOnlyTypeIsNotTriggerable(string $type): void
    {
        self::assertFalse(UserTriggerableSyncs::isTriggerable($type));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function displayOnlyTypes(): iterable
    {
        yield 'assets' => ['assets'];
        yield 'wallet' => ['wallet'];
        yield 'planetary' => ['planetary'];
        yield 'unknown' => ['does-not-exist'];
    }
}
