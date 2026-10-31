<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MiningEntry;
use App\Entity\PveExpense;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\MiningEntryRepository;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(PveIncomeRepository::class)]
#[CoversClass(PveExpenseRepository::class)]
#[CoversClass(MiningEntryRepository::class)]
#[Group('issue-243')]
final class LedgerOldestEntryDateRepositoriesTest extends IntegrationTestCase
{
    private const int VELDSPAR = 1230;
    private const int SCORDITE = 1228;
    private const int PYROXERES = 1224;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
        $this->otherUser = $this->createUser();
    }

    public function testOldestIncomeDateIsTheOldestOfTheUserNotOfAnotherUser(): void
    {
        $this->addIncome($this->user, '2026-03-14 08:30:00', 2450000.0);
        $this->addIncome($this->user, '2025-11-02 21:15:00', 1875000.0);
        $this->addIncome($this->otherUser, '2024-01-05 06:45:00', 3125000.0);
        $this->flushAndClear();

        $oldestDate = self::getContainer()->get(PveIncomeRepository::class)->findOldestDate($this->reloaded($this->user));

        $this->assertSame('2025-11-02 21:15:00', $oldestDate?->format('Y-m-d H:i:s'));
    }

    public function testOldestExpenseDateIsTheOldestOfTheUserNotOfAnotherUser(): void
    {
        $this->addExpense($this->user, '2026-02-10', 500000.0);
        $this->addExpense($this->user, '2025-12-24', 742000.0);
        $this->addExpense($this->otherUser, '2023-07-01', 96000.0);
        $this->flushAndClear();

        $oldestDate = self::getContainer()->get(PveExpenseRepository::class)->findOldestDate($this->reloaded($this->user));

        $this->assertSame('2025-12-24', $oldestDate?->format('Y-m-d'));
    }

    public function testOldestMiningEntryDateIsTheOldestOfTheUserNotOfAnotherUser(): void
    {
        $this->addMiningEntry($this->user, '2026-01-08', self::VELDSPAR, 'Veldspar', 4211);
        $this->addMiningEntry($this->user, '2025-10-19', self::SCORDITE, 'Scordite', 2906);
        $this->addMiningEntry($this->otherUser, '2022-05-30', self::PYROXERES, 'Pyroxeres', 517);
        $this->flushAndClear();

        $oldestDate = self::getContainer()->get(MiningEntryRepository::class)->findOldestDate($this->reloaded($this->user));

        $this->assertSame('2025-10-19', $oldestDate?->format('Y-m-d'));
    }

    public function testUserWithoutAnyEntryHasNoOldestDateEvenWhenAnotherUserHasSome(): void
    {
        $this->addIncome($this->otherUser, '2025-09-17 12:00:00', 1500000.0);
        $this->addExpense($this->otherUser, '2025-09-18', 250000.0);
        $this->addMiningEntry($this->otherUser, '2025-09-19', self::VELDSPAR, 'Veldspar', 1200);
        $this->flushAndClear();
        $user = $this->reloaded($this->user);

        $this->assertSame([null, null, null], [
            self::getContainer()->get(PveIncomeRepository::class)->findOldestDate($user),
            self::getContainer()->get(PveExpenseRepository::class)->findOldestDate($user),
            self::getContainer()->get(MiningEntryRepository::class)->findOldestDate($user),
        ]);
    }

    private function addIncome(User $user, string $date, float $amount): void
    {
        $income = new PveIncome();
        $income->setUser($user);
        $income->setType(PveIncomeType::Bounty);
        $income->setDescription('Bounty prizes');
        $income->setAmount($amount);
        $income->setDate(new \DateTimeImmutable($date));
        $this->em->persist($income);
    }

    private function addExpense(User $user, string $date, float $amount): void
    {
        $expense = new PveExpense();
        $expense->setUser($user);
        $expense->setType(PveExpense::TYPE_AMMO);
        $expense->setDescription('4000x Republic Fleet EMP M');
        $expense->setAmount($amount);
        $expense->setDate(new \DateTimeImmutable($date));
        $this->em->persist($expense);
    }

    private function addMiningEntry(User $user, string $date, int $typeId, string $typeName, int $quantity): void
    {
        $entry = (new MiningEntry())
            ->setUser($user)
            ->setCharacterId(93000001)
            ->setCharacterName('Maren Esk')
            ->setDate(new \DateTimeImmutable($date))
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setSolarSystemId(30003504)
            ->setSolarSystemName('Aldranette')
            ->setQuantity($quantity)
            ->setUnitPrice(17.25);
        $this->em->persist($entry);
    }

    private function reloaded(User $user): User
    {
        return $this->em->getRepository(User::class)->find($user->getId());
    }
}
