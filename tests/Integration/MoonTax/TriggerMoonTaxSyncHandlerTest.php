<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonTax;

use App\Message\SyncMoonTax;
use App\Message\TriggerMoonTaxSync;
use App\MessageHandler\TriggerMoonTaxSyncHandler;
use App\Repository\MoonTaxRefinerySettingRepository;
use App\Service\Admin\SyncTracker;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(TriggerMoonTaxSyncHandler::class)]
#[CoversClass(MoonTaxRefinerySettingRepository::class)]
#[Group('issue-273')]
final class TriggerMoonTaxSyncHandlerTest extends IntegrationTestCase
{
    use SeedsMoonTax;
    use StubsMoonTaxEsi;

    public function testOneSyncIsDispatchedPerCorporationWithAnOpenRefineryAndNoneForTheOthers(): void
    {
        $this->persistSetting(self::ROCK_HOLLOW_ID);
        $this->persistSetting(self::DEEP_CORE_ID, true, \App\Enum\MoonTaxPeriodKind::Monthly, 2);
        $this->persistSetting(self::ROCK_HOLLOW_ID, false, \App\Enum\MoonTaxPeriodKind::Weekly, 1, self::OTHER_OWNER_CORPORATION);
        $this->persistSetting(self::MARKET_KEEPSTAR_ID, true, \App\Enum\MoonTaxPeriodKind::Weekly, 1, self::NEIGHBOR_CORPORATION);
        $this->em->flush();

        $this->trigger();

        self::assertSame([self::CORPORATION, self::NEIGHBOR_CORPORATION], $this->dispatchedCorporationIds());
    }

    public function testNothingIsDispatchedWhenNoRefineryIsOpenAndTheRunIsStillTracked(): void
    {
        $this->persistSetting(self::ROCK_HOLLOW_ID, false);
        $this->em->flush();

        $this->trigger();

        $trackedRuns = array_values(array_filter(
            self::getContainer()->get(SyncTracker::class)->getAll(),
            static fn (array $entry): bool => $entry['type'] === 'moon-tax',
        ));
        self::assertSame(
            [[], [['ok', 3600, '0 corporations dispatched']]],
            [$this->dispatchedCorporationIds(), array_map(static fn (array $entry): array => [$entry['status'], $entry['expected_interval'], $entry['message']], $trackedRuns)],
        );
    }

    public function testTheTriggerNeitherCallsEsiNorWritesAnyMoonTaxData(): void
    {
        $this->replaceEsi();
        $this->persistSetting(self::ROCK_HOLLOW_ID);
        $this->em->flush();

        $this->trigger();

        self::assertSame([], $this->esiCalls);
    }

    private function trigger(): void
    {
        self::getContainer()->get(TriggerMoonTaxSyncHandler::class)(new TriggerMoonTaxSync());
    }

    private function dispatchedCorporationIds(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $corporationIds = array_map(
            static fn ($envelope): int => $envelope->getMessage() instanceof SyncMoonTax ? $envelope->getMessage()->corporationId : 0,
            $transport->getSent(),
        );
        sort($corporationIds);

        return $corporationIds;
    }
}
