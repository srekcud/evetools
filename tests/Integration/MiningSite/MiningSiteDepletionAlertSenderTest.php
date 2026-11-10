<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\Notification;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSiteStatus;
use App\Service\MiningSite\MiningSiteDepletionAlertSender;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\BuildsHaulerPlanOperation;
use App\Tests\Integration\MiningOperation\ShiftsLedgerFreshness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(MiningSiteDepletionAlertSender::class)]
#[Group('issue-271')]
final class MiningSiteDepletionAlertSenderTest extends IntegrationTestCase
{
    use BuildsHaulerPlanOperation;
    use ClockSensitiveTrait;
    use ShiftsLedgerFreshness;

    private const string DEPLETION_TITLE = 'Mining site almost depleted';
    private const string DOWNTIME_TITLE = 'Mining site not emptied before downtime';
    private const string SITE_LABEL = 'Anomalie Veldspar';
    private const string OPERATION_LABEL = 'Anomalie Veldspar — 20:00';
    private const string NINE_MINUTES_BEFORE_THE_SITE_ETA = '2026-11-10T21:01:00+00:00';
    private const string ELEVEN_MINUTES_BEFORE_THE_SITE_ETA = '2026-11-10T20:59:00+00:00';
    private const string AFTER_THE_SITE_ETA = '2026-11-10T21:11:00+00:00';
    private const string TEN_MINUTES_BEFORE_THE_LEDGER_ETA = '2026-11-11T16:49:00+00:00';
    private const string MORNING_BEFORE_DOWNTIME = '2026-11-11T08:00:00+00:00';
    private const string AFTERNOON_AFTER_DOWNTIME = '2026-11-11T12:00:00+00:00';
    private const string ETA_AFTER_DOWNTIME = '2026-11-11T14:00:00+00:00';
    private const string ETA_BEFORE_DOWNTIME = '2026-11-11T10:30:00+00:00';
    private const string ETA_BEFORE_NEXT_DOWNTIME = '2026-11-12T09:00:00+00:00';
    private const string ETA_AFTER_NEXT_DOWNTIME = '2026-11-12T12:00:00+00:00';
    private const string JUST_BEFORE_DOWNTIME = '2026-11-11T10:55:00+00:00';
    private const string ETA_JUST_AFTER_DOWNTIME = '2026-11-11T11:03:00+00:00';

    private MiningSiteDepletionAlertSender $sender;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::HAULER_PLAN_NOW));
        parent::setUp();
        $this->seedHaulerPlanSde();
        $this->sender = self::getContainer()->get(MiningSiteDepletionAlertSender::class);
    }

    public function testSiteDepletingInNineMinutesNotifiesEveryParticipantOnceAndRecordsTheAlert(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->clockAt(self::NINE_MINUTES_BEFORE_THE_SITE_ETA);

        $this->sender->sendDueAlerts();
        $this->sender->sendDueAlerts();

        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->recipientsTitled(self::DEPLETION_TITLE));
        $aria = $this->notificationOf(self::DEPLETION_TITLE, 'Aria Solberg');
        self::assertSame(
            [Notification::CATEGORY_MINING_OPERATION, Notification::LEVEL_WARNING, '/mining/operations/' . $operation->getId()->toRfc4122()],
            [$aria->getCategory(), $aria->getLevel(), $aria->getRoute()],
        );
        self::assertSame(
            ['operationId' => $operation->getId()->toRfc4122(), 'siteId' => $this->firstSiteOf($operation)->getId()->toRfc4122(), 'kind' => 'depletion'],
            $aria->getData(),
        );
        foreach ([self::SITE_LABEL, self::OPERATION_LABEL, '21:10'] as $expectedText) {
            self::assertStringContainsString($expectedText, $aria->getMessage());
        }
        self::assertSame(self::NINE_MINUTES_BEFORE_THE_SITE_ETA, $this->firstSiteOf($operation)->getDepletionAlertedAt()?->format(\DateTimeInterface::ATOM));
        self::assertNull($this->firstSiteOf($operation)->getDowntimeAlertedAt());
    }

    public function testNothingIsSentWhileTheSiteIsDepletedInMoreThanTenMinutes(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->clockAt(self::ELEVEN_MINUTES_BEFORE_THE_SITE_ETA);

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->recipientsTitled(self::DEPLETION_TITLE));
        self::assertNull($this->firstSiteOf($operation)->getDepletionAlertedAt());
    }

    public function testNothingIsSentOnceTheEtaHasPassed(): void
    {
        $this->persistHaulerPlanOperation();
        $this->clockAt(self::AFTER_THE_SITE_ETA);

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->recipientsTitled(self::DEPLETION_TITLE));
    }

    public function testSingleScanSiteIsAlertedFromTheLedgerEta(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->setTrendEtaOfTheScan($operation, null);
        $this->freshenLedgerEntries(['Aria Solberg' => '2026-11-10T20:04:00+00:00', 'Brann Holt' => '2026-11-10T20:10:00+00:00', 'Cyra Lune' => '2026-11-10T20:06:00+00:00']);
        $this->clockAt(self::TEN_MINUTES_BEFORE_THE_LEDGER_ETA);

        $this->sender->sendDueAlerts();

        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->recipientsTitled(self::DEPLETION_TITLE));
        self::assertStringContainsString('16:59', $this->notificationOf(self::DEPLETION_TITLE, 'Brann Holt')->getMessage());
    }

    public function testSiteWithoutAnyEtaIsNotAlerted(): void
    {
        $operation = $this->persistHaulerPlanOperation(readableStartSnapshots: false);
        $this->setTrendEtaOfTheScan($operation, null);
        $this->clockAt(self::TEN_MINUTES_BEFORE_THE_LEDGER_ETA);

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->recipientsTitled(self::DEPLETION_TITLE));
        self::assertSame([], $this->recipientsTitled(self::DOWNTIME_TITLE));
    }

    public function testSiteOfAnOperationThatIsNotRunningIsNotAlerted(): void
    {
        $this->persistHaulerPlanOperation(status: MiningOperationStatus::Closing);
        $this->clockAt(self::NINE_MINUTES_BEFORE_THE_SITE_ETA);

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->recipientsTitled(self::DEPLETION_TITLE));
    }

    public function testClosedSiteIsNotAlerted(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->firstSiteOf($operation)->setStatus(MiningSiteStatus::Closed);
        $this->em->flush();
        $this->clockAt(self::NINE_MINUTES_BEFORE_THE_SITE_ETA);

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->recipientsTitled(self::DEPLETION_TITLE));
    }

    public function testSiteThatWillNotBeEmptiedBeforeTheDowntimeNotifiesEveryParticipantOnce(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->setTrendEtaOfTheScan($operation, self::ETA_AFTER_DOWNTIME);
        $this->clockAt(self::MORNING_BEFORE_DOWNTIME);

        $this->sender->sendDueAlerts();
        $this->sender->sendDueAlerts();

        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->recipientsTitled(self::DOWNTIME_TITLE));
        $brann = $this->notificationOf(self::DOWNTIME_TITLE, 'Brann Holt');
        self::assertSame(
            [Notification::CATEGORY_MINING_OPERATION, Notification::LEVEL_WARNING, '/mining/operations/' . $operation->getId()->toRfc4122()],
            [$brann->getCategory(), $brann->getLevel(), $brann->getRoute()],
        );
        self::assertSame(
            ['operationId' => $operation->getId()->toRfc4122(), 'siteId' => $this->firstSiteOf($operation)->getId()->toRfc4122(), 'kind' => 'downtime'],
            $brann->getData(),
        );
        foreach ([self::SITE_LABEL, self::OPERATION_LABEL, '11:00', '14:00'] as $expectedText) {
            self::assertStringContainsString($expectedText, $brann->getMessage());
        }
        self::assertSame(self::MORNING_BEFORE_DOWNTIME, $this->firstSiteOf($operation)->getDowntimeAlertedAt()?->format(\DateTimeInterface::ATOM));
        self::assertNull($this->firstSiteOf($operation)->getDepletionAlertedAt());
        self::assertSame([], $this->recipientsTitled(self::DEPLETION_TITLE));
    }

    public function testSiteThatWillBeEmptiedBeforeTheDowntimeIsNotWarned(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->setTrendEtaOfTheScan($operation, self::ETA_BEFORE_DOWNTIME);
        $this->clockAt(self::MORNING_BEFORE_DOWNTIME);

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->recipientsTitled(self::DOWNTIME_TITLE));
        self::assertNull($this->firstSiteOf($operation)->getDowntimeAlertedAt());
    }

    public function testTheNextDowntimeIsTheOneOfTheFollowingDayOnceTheOneOfTheDayHasPassed(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->clockAt(self::AFTERNOON_AFTER_DOWNTIME);

        $this->setTrendEtaOfTheScan($operation, self::ETA_BEFORE_NEXT_DOWNTIME);
        $this->sender->sendDueAlerts();
        self::assertSame([], $this->recipientsTitled(self::DOWNTIME_TITLE));

        $this->setTrendEtaOfTheScan($operation, self::ETA_AFTER_NEXT_DOWNTIME);
        $this->sender->sendDueAlerts();
        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->recipientsTitled(self::DOWNTIME_TITLE));
    }

    public function testSiteDepletedRightAfterTheDowntimeRaisesBothAlertsIndependently(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->setTrendEtaOfTheScan($operation, self::ETA_JUST_AFTER_DOWNTIME);
        $this->clockAt(self::JUST_BEFORE_DOWNTIME);

        $this->sender->sendDueAlerts();

        self::assertCount(3, $this->recipientsTitled(self::DEPLETION_TITLE));
        self::assertCount(3, $this->recipientsTitled(self::DOWNTIME_TITLE));
        self::assertNotNull($this->firstSiteOf($operation)->getDepletionAlertedAt());
        self::assertNotNull($this->firstSiteOf($operation)->getDowntimeAlertedAt());
    }

    private function clockAt(string $instant): void
    {
        self::mockTime(new \DateTimeImmutable($instant));
    }

    private function setTrendEtaOfTheScan(MiningOperation $operation, ?string $trendEta): void
    {
        $scan = $this->em->getRepository(MiningSiteScan::class)->findOneBy(['site' => $this->firstSiteOf($operation)]);
        \assert($scan instanceof MiningSiteScan);
        $scan->setTrendEta(null === $trendEta ? null : new \DateTimeImmutable($trendEta));
        $this->em->flush();
    }

    private function firstSiteOf(MiningOperation $operation): MiningSite
    {
        $site = $this->em->getRepository(MiningSite::class)->findOneBy(['operation' => $operation]);
        \assert($site instanceof MiningSite);

        return $site;
    }

    /**
     * @return list<string>
     */
    private function recipientsTitled(string $title): array
    {
        return array_column($this->receivedTitled($title), 'recipient');
    }

    private function notificationOf(string $title, string $recipient): Notification
    {
        foreach ($this->receivedTitled($title) as $received) {
            if ($received['recipient'] === $recipient) {
                return $received['notification'];
            }
        }

        throw new \LogicException(sprintf('%s received no "%s" notification', $recipient, $title));
    }

    /**
     * @return list<array{recipient: string, notification: Notification}>
     */
    private function receivedTitled(string $title): array
    {
        $namesByUserId = [];
        foreach (['Aria Solberg' => $this->aria, 'Brann Holt' => $this->brann, 'Cyra Lune' => $this->cyra, 'Dorn Achar' => $this->dorn] as $name => $user) {
            $namesByUserId[$user->getId()->toRfc4122()] = $name;
        }

        $received = [];
        foreach ($this->em->getRepository(Notification::class)->findBy(['category' => Notification::CATEGORY_MINING_OPERATION, 'title' => $title]) as $notification) {
            $received[] = ['recipient' => $namesByUserId[$notification->getUser()->getId()->toRfc4122()], 'notification' => $notification];
        }
        usort($received, static fn (array $left, array $right): int => $left['recipient'] <=> $right['recipient']);

        return $received;
    }
}
