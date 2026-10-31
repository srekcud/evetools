<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(PveIncomeRepository::class)]
#[Group('issue-243')]
final class PveIncomeRepositoryCorpProjectImportsTest extends IntegrationTestCase
{
    private const string TRITANIUM_DRIVE_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
    private const string VELDSPAR_HAUL_ID = 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e';
    private const int PILOT_ONE_ID = 2112000001;
    private const int PILOT_TWO_ID = 2112000002;
    private const int OTHER_USER_PILOT_ID = 2112000099;

    private PveIncomeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(PveIncomeRepository::class);
    }

    public function testCorpProjectImportsOfTheUserAreReturnedWithPairQuantityAndIdentifierWhateverTheOtherIncomesAndUsers(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->addPairedImport($user, self::TRITANIUM_DRIVE_ID, self::PILOT_ONE_ID, 600, 1600, 7500.0);
        $this->addPairedImport($user, self::VELDSPAR_HAUL_ID, self::PILOT_TWO_ID, 840, 840, 6090.0);
        $this->addLegacyImport($user, self::TRITANIUM_DRIVE_ID, self::PILOT_TWO_ID, 'Tritanium Drive: 1000x Tritanium', 1000, 12500.0);
        $this->addLegacyImport($user, self::VELDSPAR_HAUL_ID, self::PILOT_ONE_ID, 'Veldspar haul reward', null, 3625.0);
        $this->addIncome($user, PveIncomeType::LootSale, '6x Rogue Drone Infestation Data', 2460000.0, 8200031);
        $this->addIncome($user, PveIncomeType::LootContract, 'Loot contract 7310042', 3125000.0, null);
        $this->addPairedImport($otherUser, self::TRITANIUM_DRIVE_ID, self::OTHER_USER_PILOT_ID, 450, 450, 5625.0);
        $this->flushAndClear();

        $imports = $this->repository->findCorpProjectImports($this->reloaded($user));
        usort($imports, static fn (array $first, array $second): int => $first['transactionId'] <=> $second['transactionId']);

        $expectedImports = [
            $this->importRow(self::TRITANIUM_DRIVE_ID, self::PILOT_ONE_ID, 600, -abs(crc32(self::TRITANIUM_DRIVE_ID . '_' . self::PILOT_ONE_ID . '_1600'))),
            $this->importRow(self::VELDSPAR_HAUL_ID, self::PILOT_TWO_ID, 840, -abs(crc32(self::VELDSPAR_HAUL_ID . '_' . self::PILOT_TWO_ID . '_840'))),
            $this->importRow(null, null, 1000, -abs(crc32(self::TRITANIUM_DRIVE_ID . '_' . self::PILOT_TWO_ID))),
            $this->importRow(null, null, null, -abs(crc32(self::VELDSPAR_HAUL_ID . '_' . self::PILOT_ONE_ID))),
        ];
        usort($expectedImports, static fn (array $first, array $second): int => $first['transactionId'] <=> $second['transactionId']);
        $this->assertSame($expectedImports, $imports);
    }

    public function testUserWithoutCorpProjectIncomeHasNoImport(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->addIncome($user, PveIncomeType::Bounty, 'Bounty prizes', 2450000.0, null);
        $this->addPairedImport($otherUser, self::VELDSPAR_HAUL_ID, self::OTHER_USER_PILOT_ID, 300, 300, 2175.0);
        $this->flushAndClear();

        $this->assertSame([], $this->repository->findCorpProjectImports($this->reloaded($user)));
    }

    private function addPairedImport(User $user, string $projectId, int $characterId, int $quantity, int $contributed, float $amount): void
    {
        $income = $this->addIncome($user, PveIncomeType::CorpProject, "Project: {$quantity}x Ore", $amount, -abs(crc32("{$projectId}_{$characterId}_{$contributed}")));
        $income->setCorpProjectId($projectId);
        $income->setCorpProjectCharacterId($characterId);
        $income->setCorpProjectQuantity($quantity);
    }

    private function addLegacyImport(User $user, string $projectId, int $characterId, string $description, ?int $recoveredQuantity, float $amount): void
    {
        $income = $this->addIncome($user, PveIncomeType::CorpProject, $description, $amount, -abs(crc32("{$projectId}_{$characterId}")));
        $income->setCorpProjectQuantity($recoveredQuantity);
    }

    private function addIncome(User $user, PveIncomeType $type, string $description, float $amount, ?int $transactionId): PveIncome
    {
        $income = new PveIncome();
        $income->setUser($user);
        $income->setType($type);
        $income->setDescription($description);
        $income->setAmount($amount);
        $income->setDate(new \DateTimeImmutable('2026-10-28 09:15:00'));
        $income->setTransactionId($transactionId);
        $this->em->persist($income);

        return $income;
    }

    private function importRow(?string $projectId, ?int $characterId, ?int $quantity, int $transactionId): array
    {
        return [
            'corpProjectId' => $projectId,
            'corpProjectCharacterId' => $characterId,
            'corpProjectQuantity' => $quantity,
            'transactionId' => $transactionId,
        ];
    }

    private function reloaded(User $user): User
    {
        return $this->em->getRepository(User::class)->find($user->getId());
    }
}
