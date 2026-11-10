<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Pve;

use App\Entity\Escalation;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\EscalationSaleStatus;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Service\Pve\EscalationIncomeSynchronizer;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-533')]
final class EscalationIncomeSynchronizerTest extends IntegrationTestCase
{
    private const string SOLD_AT = '2026-11-03 14:20:00';

    private EscalationIncomeSynchronizer $synchronizer;
    private User $pilot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->synchronizer = new EscalationIncomeSynchronizer(self::getContainer()->get(PveIncomeRepository::class), $this->em);
        $this->pilot = $this->createUser();
    }

    public function testSoldEscalationCreatesAnIncomeWithItsPriceItsSaleDateAndItsSystemDescription(): void
    {
        $escalation = $this->soldEscalation(100_000_000);
        $this->em->flush();

        $this->synchronizer->synchronize($escalation);
        $this->flushAndClear();

        $incomes = $this->escalationIncomes();
        self::assertCount(1, $incomes);
        self::assertSame(
            [PveIncomeType::Escalation, 100_000_000.0, self::SOLD_AT, 'Blood Raider Hideaway - Aldranette'],
            [$incomes[0]->getType(), $incomes[0]->getAmount(), $incomes[0]->getDate()->format('Y-m-d H:i:s'), $incomes[0]->getDescription()],
        );
        self::assertSame($this->pilot->getId()?->toRfc4122(), $incomes[0]->getUser()->getId()?->toRfc4122());
        self::assertSame($escalation->getId()?->toRfc4122(), $incomes[0]->getEscalation()?->getId()?->toRfc4122());
    }

    public function testChangingThePriceOfASoldEscalationUpdatesTheSameIncomeAndKeepsItsDate(): void
    {
        $escalation = $this->soldEscalation(100_000_000);
        $this->em->flush();
        $this->synchronizer->synchronize($escalation);
        $this->em->flush();

        $escalation->setPrice(80_000_000);
        $this->synchronizer->synchronize($escalation);
        $this->flushAndClear();

        $incomes = $this->escalationIncomes();
        self::assertCount(1, $incomes);
        self::assertSame([80_000_000.0, self::SOLD_AT], [$incomes[0]->getAmount(), $incomes[0]->getDate()->format('Y-m-d H:i:s')]);
    }

    public function testPuttingASoldEscalationBackOnSaleDeletesItsIncome(): void
    {
        $escalation = $this->soldEscalation(100_000_000);
        $this->em->flush();
        $this->synchronizer->synchronize($escalation);
        $this->em->flush();
        self::assertCount(1, $this->escalationIncomes());

        $escalation->setSaleStatus(EscalationSaleStatus::EnVente);
        $this->synchronizer->synchronize($escalation);
        $this->flushAndClear();

        self::assertSame([], $this->escalationIncomes());
    }

    public function testSavingASoldEscalationAgainKeepsASingleIncomeOnTheOriginalSaleDate(): void
    {
        $escalation = $this->soldEscalation(100_000_000);
        $this->em->flush();
        $this->synchronizer->synchronize($escalation);
        $this->em->flush();

        $escalation->setSaleStatus(EscalationSaleStatus::Vendu);
        $escalation->setNotes('Paid by contract');
        $this->synchronizer->synchronize($escalation);
        $this->flushAndClear();

        $incomes = $this->escalationIncomes();
        self::assertCount(1, $incomes);
        self::assertSame([100_000_000.0, self::SOLD_AT], [$incomes[0]->getAmount(), $incomes[0]->getDate()->format('Y-m-d H:i:s')]);
    }

    public function testEscalationStillOnSaleHasNoIncome(): void
    {
        $escalation = $this->escalation(100_000_000);
        $this->em->flush();

        $this->synchronizer->synchronize($escalation);
        $this->flushAndClear();

        self::assertSame([], $this->escalationIncomes());
    }

    public function testSoldEscalationWithAPriceOfZeroKeepsAnIncomeOfZero(): void
    {
        $escalation = $this->soldEscalation(0);
        $this->em->flush();

        $this->synchronizer->synchronize($escalation);
        $this->em->flush();
        $this->synchronizer->synchronize($escalation);
        $this->flushAndClear();

        $incomes = $this->escalationIncomes();
        self::assertCount(1, $incomes);
        self::assertSame(0.0, $incomes[0]->getAmount());
    }

    public function testDeletingTheEscalationKeepsItsIncomeWithoutLink(): void
    {
        $escalation = $this->soldEscalation(100_000_000);
        $this->em->flush();
        $this->synchronizer->synchronize($escalation);
        $this->em->flush();

        $this->em->remove($escalation);
        $this->flushAndClear();

        $incomes = $this->escalationIncomes();
        self::assertCount(1, $incomes);
        self::assertSame(100_000_000.0, $incomes[0]->getAmount());
        self::assertNull($incomes[0]->getEscalation());
    }

    public function testTwoIncomesCannotPointAtTheSameEscalation(): void
    {
        $escalation = $this->soldEscalation(100_000_000);
        $this->em->flush();
        $this->synchronizer->synchronize($escalation);
        $this->em->flush();

        $this->em->persist(
            (new PveIncome())
                ->setUser($this->pilot)
                ->setType(PveIncomeType::Escalation)
                ->setDescription('Duplicate')
                ->setAmount(1.0)
                ->setEscalation($escalation),
        );

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    private function escalation(int $price): Escalation
    {
        $escalation = (new Escalation())
            ->setUser($this->pilot)
            ->setCharacterId(2_112_533_001)
            ->setCharacterName('Ilse Varn')
            ->setType('Blood Raider Hideaway')
            ->setSolarSystemId(30_002_813)
            ->setSolarSystemName('Aldranette')
            ->setSecurityStatus(-0.4)
            ->setPrice($price)
            ->setCorporationId(98_533_001)
            ->setExpiresAt(new \DateTimeImmutable('2026-11-20 00:00:00'));
        $this->em->persist($escalation);

        return $escalation;
    }

    private function soldEscalation(int $price): Escalation
    {
        $escalation = $this->escalation($price);
        $escalation->setSaleStatus(EscalationSaleStatus::Vendu);
        $escalation->setSoldAt(new \DateTimeImmutable(self::SOLD_AT));

        return $escalation;
    }

    private function escalationIncomes(): array
    {
        return $this->em->getRepository(PveIncome::class)->findBy(['type' => PveIncomeType::Escalation]);
    }
}
