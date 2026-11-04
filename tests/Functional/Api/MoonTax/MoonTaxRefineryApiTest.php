<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use App\Entity\MoonTaxRefinerySetting;
use App\Entity\MoonTaxSyncState;
use App\Enum\MoonTaxSyncFailure;
use App\Entity\User;
use App\Message\SyncMoonTax;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-273')]
final class MoonTaxRefineryApiTest extends MoonTaxApiTestCase
{
    private const array VALID_SETTING = ['openToAlliance' => true, 'period' => 'monthly', 'rateR4' => 2.5, 'rateR8' => 5.0, 'rateR16' => 7.75, 'rateR32' => 12.0, 'rateR64' => 100.0, 'walletDivision' => 3];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->accountant();
        $this->persistExtraction(self::ROCK_HOLLOW_ID);
        $this->persistExtraction(self::DEEP_CORE_ID, self::OTHER_OWNER_CORPORATION);
        $this->em->flush();
    }

    public function testManagerSetsTheRefineryTaxAndASecondPutReplacesItWithoutDuplicating(): void
    {
        $this->request($this->manager, 'PUT', $this->refineryPath(self::ROCK_HOLLOW_ID), self::VALID_SETTING);
        $created = [$this->statusCode(), $this->settingSummaries()];
        $this->request($this->manager, 'PUT', $this->refineryPath(self::ROCK_HOLLOW_ID), ['openToAlliance' => false, 'period' => 'weekly', 'rateR4' => 0.0, 'rateR8' => 1.25, 'rateR16' => 2.5, 'rateR32' => 3.75, 'rateR64' => 5.0, 'walletDivision' => 7]);

        self::assertSame(
            [
                [200, [[self::ROCK_HOLLOW_ID, true, 'monthly', 2.5, 5.0, 7.75, 12.0, 100.0, 3]]],
                [200, [[self::ROCK_HOLLOW_ID, false, 'weekly', 0.0, 1.25, 2.5, 3.75, 5.0, 7]]],
            ],
            [$created, [$this->statusCode(), $this->settingSummaries()]],
        );
    }

    public function testRefineryUnknownToTheCorporationIsRefusedAndNothingIsWritten(): void
    {
        $this->request($this->manager, 'PUT', $this->refineryPath(self::DEEP_CORE_ID), self::VALID_SETTING);
        $otherCorporationRefinery = $this->statusCode();
        $this->request($this->manager, 'PUT', $this->refineryPath(self::MARKET_KEEPSTAR_ID), self::VALID_SETTING);

        self::assertSame([422, 422, []], [$otherCorporationRefinery, $this->statusCode(), $this->settingSummaries()]);
    }

    #[DataProvider('settingsOutOfBounds')]
    public function testSettingOutOfBoundsIsRefusedWithAValidationErrorAndNothingIsWritten(array $setting): void
    {
        $this->request($this->manager, 'PUT', $this->refineryPath(self::ROCK_HOLLOW_ID), $setting);

        self::assertSame([422, []], [$this->statusCode(), $this->settingSummaries()]);
    }

    public static function settingsOutOfBounds(): iterable
    {
        yield 'rate below zero' => [['rateR4' => -0.01] + self::VALID_SETTING];
        yield 'rate above one hundred' => [['rateR32' => 100.01] + self::VALID_SETTING];
        yield 'wallet division zero' => [['walletDivision' => 0] + self::VALID_SETTING];
        yield 'wallet division eight' => [['walletDivision' => 8] + self::VALID_SETTING];
        yield 'unknown period' => [['period' => 'daily'] + self::VALID_SETTING];
        yield 'rate missing' => [array_diff_key(self::VALID_SETTING, ['rateR8' => 1])];
        yield 'wallet division missing' => [array_diff_key(self::VALID_SETTING, ['walletDivision' => 1])];
    }

    public function testRatesAndWalletDivisionAreAcceptedAtBothEndsOfTheirRange(): void
    {
        $lowest = ['rateR4' => 0.0, 'rateR8' => 0.0, 'rateR16' => 0.0, 'rateR32' => 0.0, 'rateR64' => 0.0, 'walletDivision' => 1] + self::VALID_SETTING;
        $highest = ['rateR4' => 100.0, 'rateR8' => 100.0, 'rateR16' => 100.0, 'rateR32' => 100.0, 'rateR64' => 100.0, 'walletDivision' => 7] + self::VALID_SETTING;

        $this->request($this->manager, 'PUT', $this->refineryPath(self::ROCK_HOLLOW_ID), $lowest);
        $lowestStatus = $this->statusCode();
        $this->request($this->manager, 'PUT', $this->refineryPath(self::ROCK_HOLLOW_ID), $highest);

        self::assertSame([200, 200, [[self::ROCK_HOLLOW_ID, true, 'monthly', 100.0, 100.0, 100.0, 100.0, 100.0, 7]]], [$lowestStatus, $this->statusCode(), $this->settingSummaries()]);
    }

    public function testCorporationStateListsTheRefinerySettingsAndTheAccessOfEachFlow(): void
    {
        $this->request($this->manager, 'PUT', $this->refineryPath(self::ROCK_HOLLOW_ID), self::VALID_SETTING);

        $this->request($this->manager, 'GET', self::BASE_PATH . '/' . self::CORPORATION);

        $body = $this->responseJson();
        $refinery = array_intersect_key($body['refineries'][0] ?? [], self::VALID_SETTING + ['structureId' => 1]);
        ksort($refinery);
        $expectedRefinery = self::VALID_SETTING + ['structureId' => self::ROCK_HOLLOW_ID];
        ksort($expectedRefinery);
        self::assertSame(
            [200, $expectedRefinery, ['ledger' => 'never_synced', 'payments' => 'never_synced']],
            [$this->statusCode(), $refinery, $body['access'] ?? null],
        );
    }

    #[DataProvider('syncStatesAndTheirAccess')]
    public function testCorporationStateExposesOneAccessPerFlowFromTheSyncState(?string $observersSyncedAt, ?MoonTaxSyncFailure $observersFailure, ?string $walletSyncedAt, ?MoonTaxSyncFailure $walletFailure, array $expectedAccess): void
    {
        $this->em->persist(
            (new MoonTaxSyncState())
                ->setCorporationId(self::CORPORATION)
                ->setObserversSyncedAt($observersSyncedAt === null ? null : new \DateTimeImmutable($observersSyncedAt))
                ->setObserversFailure($observersFailure)
                ->setWalletSyncedAt($walletSyncedAt === null ? null : new \DateTimeImmutable($walletSyncedAt))
                ->setWalletFailure($walletFailure),
        );

        $this->request($this->manager, 'GET', self::BASE_PATH . '/' . self::CORPORATION);

        self::assertSame([200, $expectedAccess], [$this->statusCode(), $this->responseJson()['access'] ?? null]);
    }

    public static function syncStatesAndTheirAccess(): iterable
    {
        yield 'synced without failure' => ['2026-11-10T11:00:00+00:00', null, '2026-11-10T11:00:00+00:00', null, ['ledger' => 'ok', 'payments' => 'ok']];
        yield 'denied ledger and reconnection needed for the payments' => ['2026-11-09T11:00:00+00:00', MoonTaxSyncFailure::NoAccountantToken, null, MoonTaxSyncFailure::ReconnectRequired, ['ledger' => 'denied', 'payments' => 'reconnect']];
        yield 'esi errors' => [null, MoonTaxSyncFailure::EsiError, '2026-11-09T11:00:00+00:00', MoonTaxSyncFailure::EsiError, ['ledger' => 'error', 'payments' => 'error']];
        yield 'ledger synced and payments never synced' => ['2026-11-10T11:00:00+00:00', null, null, null, ['ledger' => 'ok', 'payments' => 'never_synced']];
    }

    public function testManagerTriggersTheSyncOfHisCorporationOnDemand(): void
    {
        $this->request($this->manager, 'POST', self::BASE_PATH . '/' . self::CORPORATION . '/sync', []);

        $sent = array_map(static fn ($envelope): array => [$envelope->getMessage()::class, $envelope->getMessage() instanceof SyncMoonTax ? $envelope->getMessage()->corporationId : null], $this->asyncTransport()->getSent());
        self::assertSame([200, [[SyncMoonTax::class, self::CORPORATION]]], [$this->statusCode(), $sent]);
    }

    public function testUserWithoutAccountantCanNeitherChangeTheSettingsNorTriggerTheSync(): void
    {
        $this->persistCharacterOfCorporation('Aurel Kade', self::AUREL_ID, self::CORPORATION, ['Director']);
        $this->em->flush();
        $director = $this->usersByEveCharacterId[self::AUREL_ID];

        $this->request($director, 'PUT', $this->refineryPath(self::ROCK_HOLLOW_ID), self::VALID_SETTING);
        $putStatus = $this->statusCode();
        $this->request($director, 'POST', self::BASE_PATH . '/' . self::CORPORATION . '/sync', []);

        self::assertSame([403, 403, [], []], [$putStatus, $this->statusCode(), $this->settingSummaries(), $this->asyncTransport()->getSent()]);
    }

    private function refineryPath(int $structureId): string
    {
        return sprintf('%s/%d/refineries/%d', self::BASE_PATH, self::CORPORATION, $structureId);
    }

    private function settingSummaries(): array
    {
        $this->em->clear();

        return array_map(
            static fn (MoonTaxRefinerySetting $setting): array => [$setting->getStructureId(), $setting->isOpenToAlliance(), $setting->getPeriod()->value, $setting->getRateR4(), $setting->getRateR8(), $setting->getRateR16(), $setting->getRateR32(), $setting->getRateR64(), $setting->getWalletDivision()],
            $this->em->getRepository(MoonTaxRefinerySetting::class)->findAll(),
        );
    }
}
