<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

#[Group('issue-271')]
final class MiningOperationNotificationsApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;

    private const string PAID_AT = '2026-11-05T22:24:00+00:00';
    private const string JOIN_CODE = '7f3a9c2e1b';
    private const string SHARES_COMPUTED = 'Mining shares computed';
    private const string PAYMENT_RECEIVED = 'Payment received';
    private const string JOIN_REQUEST = 'Mining operation join request';

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
    }

    public function testFreezingTheSharesNotifiesEachParticipantInTheNotificationsOfHisOwnAccount(): void
    {
        $this->g1();

        $route = '/mining-operations/' . $this->spodumain->getId();
        self::assertSame(
            [
                'Aria Solberg' => [['mining_operation', 'warning', self::SHARES_COMPUTED, $route]],
                'Brann Holt' => [['mining_operation', 'warning', self::SHARES_COMPUTED, $route]],
                'Cyra Lune' => [['mining_operation', 'warning', self::SHARES_COMPUTED, $route]],
                'Dorn Achar' => [],
                'Elia Varr' => [],
            ],
            $this->notificationsOfEveryPilot(self::SHARES_COMPUTED),
        );
    }

    public function testMarkingAPaymentPaidNotifiesItsBeneficiaryAndNobodyElse(): void
    {
        $this->g1();

        $this->markAs($this->aria, 0, true, self::PAID_AT);

        self::assertResponseStatusCodeSame(200);
        $received = $this->notificationsOfEveryPilot(self::PAYMENT_RECEIVED);
        self::assertSame(
            [
                'Aria Solberg' => [],
                'Brann Holt' => [],
                'Cyra Lune' => [['mining_operation', 'success', self::PAYMENT_RECEIVED, '/mining-operations/' . $this->spodumain->getId()]],
                'Dorn Achar' => [],
                'Elia Varr' => [],
            ],
            $received,
        );
    }

    public function testUnmarkingAPaymentDoesNotNotifyItsBeneficiaryAgain(): void
    {
        $this->g1();
        $this->markAs($this->aria, 0, true, self::PAID_AT);

        $this->markAs($this->aria, 0, false, '2026-11-05T22:30:00+00:00');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->notificationsOfEveryPilot(self::PAYMENT_RECEIVED)['Cyra Lune']);
    }

    public function testAJoinRequestNotifiesTheOwnerOnly(): void
    {
        $this->spodumain->setJoinCode(self::JOIN_CODE)->setCorporationId(self::C2);
        $this->em->flush();
        $this->as($this->elia);

        $this->request('POST', '/api/mining-operations/join/' . self::JOIN_CODE, ['characterIds' => [(string) $this->miningCharacters['Elia Varr']->getId()]]);

        self::assertSame(['pending'], [$this->responseJson()['myStatus'] ?? null]);
        self::assertSame(
            [
                'Aria Solberg' => [['mining_operation', 'info', self::JOIN_REQUEST, '/mining-operations/' . $this->spodumain->getId()]],
                'Brann Holt' => [],
                'Cyra Lune' => [],
                'Dorn Achar' => [],
                'Elia Varr' => [],
            ],
            $this->notificationsOfEveryPilot(self::JOIN_REQUEST),
        );
    }

    private function markAs(User $user, int $position, bool $paid, string $at): void
    {
        $paymentId = $this->paymentIdAt($position);
        self::mockTime(new \DateTimeImmutable($at));
        $this->as($user);
        $this->request('PATCH', sprintf('/api/mining-operations/%s/payments/%s', $this->spodumain->getId(), $paymentId), ['paid' => $paid]);
    }

    private function paymentIdAt(int $position): string
    {
        $this->as($this->aria);
        $this->request('GET', sprintf('/api/mining-operations/%s/payments', $this->spodumain->getId()));
        foreach ($this->responseJson() as $payment) {
            if (($payment['position'] ?? null) === $position) {
                return (string) $payment['id'];
            }
        }

        return Uuid::v4()->toRfc4122();
    }

    private function notificationsOfEveryPilot(string $title): array
    {
        $notificationsByPilot = [];
        foreach ([$this->aria, $this->brann, $this->cyra, $this->dorn, $this->elia] as $pilot) {
            $notificationsByPilot[$this->miningNameOf($pilot)] = $this->notificationsOf($pilot, $title);
        }
        ksort($notificationsByPilot);

        return $notificationsByPilot;
    }

    private function notificationsOf(User $user, string $title): array
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT category, level, title, route FROM notifications WHERE user_id::text = ? AND title = ?',
            [(string) $user->getId(), $title],
        );

        return array_map(static fn (array $row): array => [$row['category'], $row['level'], $row['title'], $row['route']], $rows);
    }

    private function miningNameOf(User $user): string
    {
        return $user->getMainCharacter()?->getName() ?? $user->getCharacters()->first()->getName();
    }
}
