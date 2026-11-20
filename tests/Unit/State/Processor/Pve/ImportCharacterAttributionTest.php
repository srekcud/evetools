<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Pve\ImportExpensesInput;
use App\ApiResource\Input\Pve\ImportLootContractsInput;
use App\ApiResource\Input\Pve\ImportLootSalesInput;
use App\Entity\Character;
use App\Entity\PveExpense;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\Pve\CorpProjectContributionAccounting;
use App\State\Processor\Pve\ImportExpensesProcessor;
use App\State\Processor\Pve\ImportLootContractsProcessor;
use App\State\Processor\Pve\ImportLootSalesProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(ImportLootSalesProcessor::class)]
#[CoversClass(ImportLootContractsProcessor::class)]
#[CoversClass(ImportExpensesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-1080')]
final class ImportCharacterAttributionTest extends TestCase
{
    private const int MAIN_ID = 2112080001;
    private const int FOREIGN_ID = 2112080099;

    private array $persistedEntities = [];

    public function testMarketLootSaleKeepsTheScannedCharacterWhenItIsOneOfTheUsersCharacters(): void
    {
        $this->importLootSales([$this->marketSale(['characterId' => self::MAIN_ID])]);

        $this->assertSame([self::MAIN_ID], $this->characterIdsOf(PveIncome::class));
    }

    public function testMarketLootSaleOfACharacterOutsideTheAccountIsImportedWithoutCharacter(): void
    {
        $this->importLootSales([$this->marketSale(['characterId' => self::FOREIGN_ID])]);

        $this->assertSame([null], $this->characterIdsOf(PveIncome::class));
    }

    public function testMarketLootSaleWithoutCharacterIsStillImportedWithoutCharacter(): void
    {
        $this->importLootSales([$this->marketSale([])]);

        $this->assertSame([null], $this->characterIdsOf(PveIncome::class));
    }

    public function testLootContractKeepsTheScannedCharacterWhenItIsOneOfTheUsersCharacters(): void
    {
        $this->importLootContracts([$this->contract(['characterId' => self::MAIN_ID]), $this->contract(['characterId' => self::FOREIGN_ID, 'contractId' => 710002])]);

        $this->assertSame([self::MAIN_ID, null], $this->characterIdsOf(PveIncome::class));
    }

    public function testExpenseKeepsTheScannedCharacterWhenItIsOneOfTheUsersCharacters(): void
    {
        $this->importExpenses([$this->expense(['characterId' => self::MAIN_ID]), $this->expense(['characterId' => self::FOREIGN_ID, 'transactionId' => 810002]), $this->expense(['transactionId' => 810003])]);

        $this->assertSame([self::MAIN_ID, null, null], $this->characterIdsOf(PveExpense::class));
    }

    private function importLootSales(array $sales): void
    {
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $settingsRepository = $this->settingsRepository();
        $input = new ImportLootSalesInput();
        $input->sales = $sales;

        (new ImportLootSalesProcessor(
            security: $this->security(),
            incomeRepository: $incomeRepository,
            settingsRepository: $settingsRepository,
            entityManager: $this->entityManager(),
            contributionAccounting: new CorpProjectContributionAccounting($incomeRepository, $settingsRepository),
        ))->process($input, new Post());
    }

    private function importLootContracts(array $contracts): void
    {
        $input = new ImportLootContractsInput();
        $input->contracts = $contracts;

        (new ImportLootContractsProcessor(
            security: $this->security(),
            incomeRepository: $this->createStub(PveIncomeRepository::class),
            settingsRepository: $this->settingsRepository(),
            entityManager: $this->entityManager(),
        ))->process($input, new Post());
    }

    private function importExpenses(array $expenses): void
    {
        $input = new ImportExpensesInput();
        $input->expenses = $expenses;

        (new ImportExpensesProcessor(
            security: $this->security(),
            expenseRepository: $this->createStub(PveExpenseRepository::class),
            settingsRepository: $this->settingsRepository(),
            entityManager: $this->entityManager(),
        ))->process($input, new Post());
    }

    private function marketSale(array $overrides): array
    {
        return $overrides + [
            'type' => 'loot_sale',
            'typeName' => '12x Rogue Drone Infestation Data',
            'price' => 4560000.0,
            'dateIssued' => '2026-11-15',
            'transactionId' => 8200017,
        ];
    }

    private function contract(array $overrides): array
    {
        return $overrides + [
            'contractId' => 710001,
            'price' => 1840000.0,
            'description' => '4x Overseer Effects',
            'date' => '2026-11-15',
        ];
    }

    private function expense(array $overrides): array
    {
        return $overrides + [
            'type' => PveExpense::TYPE_AMMO,
            'typeName' => '500x Scourge Heavy Missile',
            'price' => 5170.0,
            'dateIssued' => '2026-11-15',
            'transactionId' => 810001,
        ];
    }

    private function security(): Security
    {
        $user = new User();
        $user->addCharacter((new Character())->setEveCharacterId(self::MAIN_ID)->setName('Main'));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function settingsRepository(): UserPveSettingsRepository
    {
        $repository = $this->createStub(UserPveSettingsRepository::class);
        $repository->method('getOrCreate')->willReturn(new UserPveSettings());

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedEntities[] = $entity;
        });

        return $entityManager;
    }

    /**
     * @param class-string $entityClass
     *
     * @return list<int|null>
     */
    private function characterIdsOf(string $entityClass): array
    {
        return array_values(array_map(
            static fn (object $entity): ?int => $entity->getCharacterId(),
            array_filter($this->persistedEntities, static fn (object $entity): bool => $entity instanceof $entityClass),
        ));
    }
}
