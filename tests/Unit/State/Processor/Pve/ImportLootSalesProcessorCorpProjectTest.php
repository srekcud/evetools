<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Pve\ImportLootSalesInput;
use App\ApiResource\Pve\ImportResultResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\Pve\CorpProjectContributionAccounting;
use App\State\Processor\Pve\ImportLootSalesProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(ImportLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-243')]
final class ImportLootSalesProcessorCorpProjectTest extends TestCase
{
    private const PROJECT_ID = 'a1b2c3d4-project';
    private const VELDSPAR_PROJECT_ID = 'e5f6a7b8-project';
    private const PILOT_ONE_ID = 2112000001;
    private const PILOT_TWO_ID = 2112000002;
    private const CONTRIBUTION_DATE = '2026-10-28';

    private array $persistedEntities = [];

    public function testContributionWithNothingImportedIsWrittenWithItsPairAndQuantity(): void
    {
        $result = $this->import([$this->contributionSale(self::PILOT_ONE_ID, 1000, 1000, 0, 12500.0)]);

        $this->assertSame([1, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame(
            [[PveIncomeType::CorpProject, 'Tritanium Drive: 1000x Tritanium', 12500.0, self::CONTRIBUTION_DATE, -abs(crc32('a1b2c3d4-project_2112000001_1000')), self::PROJECT_ID, self::PILOT_ONE_ID, 1000]],
            $this->persistedIncomeSummaries(),
        );
    }

    public function testDeltaIsWrittenWithTheDeltaAsQuantityNotTheCumul(): void
    {
        $result = $this->import(
            [$this->contributionSale(self::PILOT_ONE_ID, 1600, 600, 1000, 7500.0)],
            corpProjectImports: [$this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000)],
        );

        $this->assertSame([1, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame(
            [[PveIncomeType::CorpProject, 'Tritanium Drive: 600x Tritanium', 7500.0, self::CONTRIBUTION_DATE, -abs(crc32('a1b2c3d4-project_2112000001_1600')), self::PROJECT_ID, self::PILOT_ONE_ID, 600]],
            $this->persistedIncomeSummaries(),
        );
    }

    public function testContributionAccountedSinceTheScanIsRejectedAndNothingIsWritten(): void
    {
        $result = $this->import(
            [$this->contributionSale(self::PILOT_ONE_ID, 1600, 600, 1000, 7500.0)],
            corpProjectImports: [
                $this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000),
                $this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 800, 1800),
            ],
        );

        $this->assertSame([0, 1], [$result->imported, $result->rejectedContributions]);
        $this->assertSame([], $this->persistedIncomeSummaries());
    }

    public function testContributionAlreadyImportedUnderTheSameIdentifierIsSkippedBeforeTheAccountingCheck(): void
    {
        $result = $this->import(
            [$this->contributionSale(self::PILOT_ONE_ID, 1600, 600, 1000, 7500.0)],
            corpProjectImports: [
                $this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000),
                $this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 600, 1600),
            ],
        );

        $this->assertSame([0, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame([], $this->persistedIncomeSummaries());
    }

    public function testDeclinedQuantityCountsInTheAccountedQuantityCheckedAtImport(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedCorpProjectQuantity(self::PROJECT_ID, self::PILOT_ONE_ID, 600);

        $result = $this->import(
            [$this->contributionSale(self::PILOT_ONE_ID, 1900, 300, 1600, 3750.0)],
            corpProjectImports: [$this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000)],
            settings: $settings,
        );

        $this->assertSame([1, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame(
            [[PveIncomeType::CorpProject, 'Tritanium Drive: 300x Tritanium', 3750.0, self::CONTRIBUTION_DATE, -abs(crc32('a1b2c3d4-project_2112000001_1900')), self::PROJECT_ID, self::PILOT_ONE_ID, 300]],
            $this->persistedIncomeSummaries(),
        );
    }

    public function testAccountedQuantityIgnoringTheDeclinedQuantityIsRejected(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedCorpProjectQuantity(self::PROJECT_ID, self::PILOT_ONE_ID, 600);

        $result = $this->import(
            [$this->contributionSale(self::PILOT_ONE_ID, 1900, 900, 1000, 11250.0)],
            corpProjectImports: [$this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000)],
            settings: $settings,
        );

        $this->assertSame([0, 1], [$result->imported, $result->rejectedContributions]);
        $this->assertSame([], $this->persistedIncomeSummaries());
    }

    public function testExplicitZeroAccountedQuantityIsAcceptedWhenNothingWasAccounted(): void
    {
        $result = $this->import([$this->contributionSale(self::PILOT_TWO_ID, 400, 400, 0, 5000.0)]);

        $this->assertSame([1, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame(
            [[PveIncomeType::CorpProject, 'Tritanium Drive: 400x Tritanium', 5000.0, self::CONTRIBUTION_DATE, -abs(crc32('a1b2c3d4-project_2112000002_400')), self::PROJECT_ID, self::PILOT_TWO_ID, 400]],
            $this->persistedIncomeSummaries(),
        );
    }

    public function testImportOfAnotherCharacterOfTheSameProjectDoesNotCountForThisPair(): void
    {
        $result = $this->import(
            [$this->contributionSale(self::PILOT_TWO_ID, 400, 400, 0, 5000.0)],
            corpProjectImports: [$this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000)],
        );

        $this->assertSame([1, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame([[self::PROJECT_ID, self::PILOT_TWO_ID, 400]], $this->persistedPairs());
    }

    public function testImportOfAnotherProjectOfTheSameCharacterDoesNotCountForThisPair(): void
    {
        $result = $this->import(
            [$this->contributionSale(self::PILOT_ONE_ID, 1000, 1000, 0, 7250.0, self::VELDSPAR_PROJECT_ID, 'Veldspar Haul: 1000x Veldspar')],
            corpProjectImports: [$this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000)],
        );

        $this->assertSame([1, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame([[self::VELDSPAR_PROJECT_ID, self::PILOT_ONE_ID, 1000]], $this->persistedPairs());
    }

    public function testLegacyImportCountsForItsRecoveredQuantityAtImport(): void
    {
        $result = $this->import(
            [$this->contributionSale(self::PILOT_ONE_ID, 1450, 450, 1000, 5625.0)],
            corpProjectImports: [$this->legacyImport(self::PILOT_ONE_ID, 1000)],
        );

        $this->assertSame([1, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame([[self::PROJECT_ID, self::PILOT_ONE_ID, 450]], $this->persistedPairs());
    }

    public function testLegacyImportWithoutRecoveredQuantityRejectsTheContribution(): void
    {
        $result = $this->import(
            [$this->contributionSale(self::PILOT_ONE_ID, 1450, 1450, 0, 18125.0)],
            corpProjectImports: [$this->legacyImport(self::PILOT_ONE_ID, null)],
        );

        $this->assertSame([0, 1], [$result->imported, $result->rejectedContributions]);
        $this->assertSame([], $this->persistedIncomeSummaries());
    }

    public function testLegacyIdentifierDeclinedBeforeLotTwoWritesNothing(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedLootSaleTransactionId(-abs(crc32('a1b2c3d4-project_2112000001')));

        $result = $this->import([$this->contributionSale(self::PILOT_ONE_ID, 1200, 1200, 0, 15000.0)], settings: $settings);

        $this->assertSame(0, $result->imported);
        $this->assertSame([], $this->persistedIncomeSummaries());
    }

    #[DataProvider('requiredContributionFields')]
    public function testContributionMissingARequiredFieldIsRejectedAndNothingIsWritten(string $missingField): void
    {
        $sale = $this->contributionSale(self::PILOT_ONE_ID, 1000, 1000, 0, 12500.0);
        unset($sale[$missingField]);

        $result = $this->import([$sale]);

        $this->assertSame([0, 1], [$result->imported, $result->rejectedContributions]);
        $this->assertSame([], $this->persistedIncomeSummaries());
    }

    public static function requiredContributionFields(): iterable
    {
        yield 'projectId' => ['projectId'];
        yield 'characterId' => ['characterId'];
        yield 'quantity' => ['quantity'];
        yield 'accountedQuantity' => ['accountedQuantity'];
    }

    public function testMarketLootSaleNeedsNoneOfTheContributionFields(): void
    {
        $result = $this->import([[
            'type' => PveIncomeType::LootSale->value,
            'typeId' => 60459,
            'typeName' => '12x Rogue Drone Infestation Data',
            'quantity' => 12,
            'price' => 4560000.0,
            'dateIssued' => '2026-10-25',
            'transactionId' => 8200017,
        ]]);

        $this->assertSame([1, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame(
            [[PveIncomeType::LootSale, '12x Rogue Drone Infestation Data', 4560000.0, '2026-10-25', 8200017, null, null, null]],
            $this->persistedIncomeSummaries(),
        );
    }

    public function testDeclinedContributionMemorisesItsQuantityForThePairInsteadOfItsIdentifier(): void
    {
        $settings = new UserPveSettings();

        $result = $this->import(
            [],
            declined: [$this->contributionSale(self::PILOT_ONE_ID, 1600, 600, 1000, 7500.0)],
            corpProjectImports: [$this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000)],
            settings: $settings,
        );

        $this->assertSame([0, 0], [$result->imported, $result->rejectedContributions]);
        $this->assertSame(['a1b2c3d4-project:2112000001' => 600], $this->declinedCorpProjectQuantitiesOf($settings));
        $this->assertSame([], $settings->getDeclinedLootSaleTransactionIds());
        $this->assertSame([], $this->persistedIncomeSummaries());
    }

    public function testDeclinedContributionAddsUpWithTheQuantityAlreadyDeclined(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedCorpProjectQuantity(self::PROJECT_ID, self::PILOT_ONE_ID, 600);

        $result = $this->import(
            [],
            declined: [$this->contributionSale(self::PILOT_ONE_ID, 1900, 300, 1600, 3750.0)],
            corpProjectImports: [$this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000)],
            settings: $settings,
        );

        $this->assertSame(0, $result->rejectedContributions);
        $this->assertSame(['a1b2c3d4-project:2112000001' => 900], $this->declinedCorpProjectQuantitiesOf($settings));
    }

    public function testDeclinedContributionAccountedSinceTheScanMemorisesNothing(): void
    {
        $settings = new UserPveSettings();

        $result = $this->import(
            [],
            declined: [$this->contributionSale(self::PILOT_ONE_ID, 1600, 600, 1000, 7500.0)],
            corpProjectImports: [
                $this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 1000, 1000),
                $this->pairedImport(self::PROJECT_ID, self::PILOT_ONE_ID, 800, 1800),
            ],
            settings: $settings,
        );

        $this->assertSame(1, $result->rejectedContributions);
        $this->assertSame([], $this->declinedCorpProjectQuantitiesOf($settings));
        $this->assertSame([], $settings->getDeclinedLootSaleTransactionIds());
    }

    public function testDeclinedContributionWithoutAccountedQuantityMemorisesNothing(): void
    {
        $settings = new UserPveSettings();
        $declinedContribution = $this->contributionSale(self::PILOT_ONE_ID, 1000, 1000, 0, 12500.0);
        unset($declinedContribution['accountedQuantity']);

        $result = $this->import([], declined: [$declinedContribution], settings: $settings);

        $this->assertSame(1, $result->rejectedContributions);
        $this->assertSame([], $this->declinedCorpProjectQuantitiesOf($settings));
        $this->assertSame([], $settings->getDeclinedLootSaleTransactionIds());
    }

    public function testDeclinedMarketSaleStillMemorisesItsTransactionIdentifier(): void
    {
        $settings = new UserPveSettings();

        $this->import([], declined: [['transactionId' => 8200023]], settings: $settings);

        $this->assertSame([8200023], $settings->getDeclinedLootSaleTransactionIds());
        $this->assertSame([], $this->declinedCorpProjectQuantitiesOf($settings));
    }

    private function import(
        array $sales,
        array $declined = [],
        array $corpProjectImports = [],
        UserPveSettings $settings = new UserPveSettings(),
    ): ImportResultResource {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));

        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn($settings);

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getImportedTransactionIds')->willReturn(array_values(array_filter(
            array_column($corpProjectImports, 'transactionId'),
            static fn (?int $transactionId): bool => $transactionId !== null,
        )));
        $incomeRepository->method('findCorpProjectImports')->willReturn($corpProjectImports);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedEntities[] = $entity;
        });

        $input = new ImportLootSalesInput();
        $input->sales = $sales;
        $input->declined = $declined;

        $processor = new ImportLootSalesProcessor(
            security: $security,
            incomeRepository: $incomeRepository,
            settingsRepository: $settingsRepository,
            entityManager: $entityManager,
            contributionAccounting: new CorpProjectContributionAccounting(
                incomeRepository: $incomeRepository,
                settingsRepository: $settingsRepository,
            ),
        );

        return $processor->process($input, new Post());
    }

    private function contributionSale(
        int $characterId,
        int $contributed,
        int $quantity,
        int $accountedQuantity,
        float $price,
        string $projectId = self::PROJECT_ID,
        ?string $typeName = null,
    ): array {
        return [
            'type' => PveIncomeType::CorpProject->value,
            'typeId' => $projectId === self::PROJECT_ID ? 34 : 1230,
            'typeName' => $typeName ?? "Tritanium Drive: {$quantity}x Tritanium",
            'quantity' => $quantity,
            'price' => $price,
            'dateIssued' => self::CONTRIBUTION_DATE,
            'transactionId' => -abs(crc32("{$projectId}_{$characterId}_{$contributed}")),
            'projectId' => $projectId,
            'characterId' => $characterId,
            'accountedQuantity' => $accountedQuantity,
        ];
    }

    private function pairedImport(string $projectId, int $characterId, int $quantity, int $contributedAtImport): array
    {
        return [
            'corpProjectId' => $projectId,
            'corpProjectCharacterId' => $characterId,
            'corpProjectQuantity' => $quantity,
            'transactionId' => -abs(crc32("{$projectId}_{$characterId}_{$contributedAtImport}")),
        ];
    }

    private function legacyImport(int $characterId, ?int $recoveredQuantity): array
    {
        return [
            'corpProjectId' => null,
            'corpProjectCharacterId' => null,
            'corpProjectQuantity' => $recoveredQuantity,
            'transactionId' => -abs(crc32(self::PROJECT_ID . '_' . $characterId)),
        ];
    }

    private function persistedIncomes(): array
    {
        return array_values(array_filter($this->persistedEntities, static fn (object $entity): bool => $entity instanceof PveIncome));
    }

    private function persistedIncomeSummaries(): array
    {
        return array_map(
            static fn (PveIncome $income): array => [
                $income->getType(),
                $income->getDescription(),
                $income->getAmount(),
                $income->getDate()->format('Y-m-d'),
                $income->getTransactionId(),
                $income->getCorpProjectId(),
                $income->getCorpProjectCharacterId(),
                $income->getCorpProjectQuantity(),
            ],
            $this->persistedIncomes(),
        );
    }

    private function persistedPairs(): array
    {
        return array_map(
            static fn (PveIncome $income): array => [$income->getCorpProjectId(), $income->getCorpProjectCharacterId(), $income->getCorpProjectQuantity()],
            $this->persistedIncomes(),
        );
    }

    private function declinedCorpProjectQuantitiesOf(UserPveSettings $settings): array
    {
        return (new \ReflectionProperty(UserPveSettings::class, 'declinedCorpProjectQuantities'))->getValue($settings);
    }
}
