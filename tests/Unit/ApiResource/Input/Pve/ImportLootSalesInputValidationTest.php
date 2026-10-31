<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Pve;

use App\ApiResource\Input\Pve\ImportLootSalesInput;
use App\Tests\Unit\ApiResource\Input\GroupIndustry\PropertyPathsOfViolations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(ImportLootSalesInput::class)]
#[Group('issue-243')]
final class ImportLootSalesInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const int MAXIMUM_ITEMS = 500;
    private const string PROJECT_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
    private const int PILOT_ONE_ID = 2112000001;
    private const int ROGUE_DRONE_INFESTATION_DATA = 60459;
    private const int TRITANIUM = 34;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testMarketLootSaleSentByTheScanIsAccepted(): void
    {
        $this->assertSame([], $this->violatedPathsOfTheSales([$this->marketLootSale()]));
    }

    public function testContributionSentByTheScanIsAccepted(): void
    {
        $this->assertSame([], $this->violatedPathsOfTheSales([$this->contribution()]));
    }

    public function testSaleWithOnlyItsNamePriceAndDateIsAccepted(): void
    {
        $this->assertSame([], $this->violatedPathsOfTheSales([['typeName' => '3x Rogue Drone Infestation Data', 'price' => 1230000.0, 'dateIssued' => '2026-10-27']]));
    }

    public function testWholeNumberPriceDecodedAsAnIntegerIsAccepted(): void
    {
        $this->assertSame([], $this->violatedPathsOfTheSales([$this->contribution(['price' => 12500])]));
    }

    #[DataProvider('invalidSaleFields')]
    public function testSaleWithAnInvalidFieldIsRejectedOnThatField(array $sale, array $expectedPropertyPaths): void
    {
        $this->assertSame($expectedPropertyPaths, $this->violatedPathsOfTheSales([$this->marketLootSale(), $sale]));
    }

    public static function invalidSaleFields(): iterable
    {
        yield 'empty type name' => [self::marketLootSaleWith(['typeName' => '']), ['sales[1][typeName]']];
        yield 'missing type name' => [self::marketLootSaleWithout('typeName'), ['sales[1][typeName]']];
        yield 'null price' => [self::marketLootSaleWith(['price' => null]), ['sales[1][price]']];
        yield 'missing price' => [self::marketLootSaleWithout('price'), ['sales[1][price]']];
        yield 'zero price' => [self::marketLootSaleWith(['price' => 0]), ['sales[1][price]']];
        yield 'zero decimal price' => [self::marketLootSaleWith(['price' => 0.0]), ['sales[1][price]']];
        yield 'negative price' => [self::marketLootSaleWith(['price' => -2460000.0]), ['sales[1][price]']];
        yield 'not a number price' => [self::marketLootSaleWith(['price' => NAN]), ['sales[1][price]']];
        yield 'price as text' => [self::marketLootSaleWith(['price' => '2460000']), ['sales[1][price]']];
        yield 'unreadable date' => [self::marketLootSaleWith(['dateIssued' => 'not-a-date']), ['sales[1][dateIssued]']];
        yield 'empty date' => [self::marketLootSaleWith(['dateIssued' => '']), ['sales[1][dateIssued]']];
        yield 'missing date' => [self::marketLootSaleWithout('dateIssued'), ['sales[1][dateIssued]']];
        yield 'transaction id as text' => [self::marketLootSaleWith(['transactionId' => '8200031']), ['sales[1][transactionId]']];
        yield 'decimal transaction id' => [self::marketLootSaleWith(['transactionId' => 8200031.5]), ['sales[1][transactionId]']];
    }

    #[DataProvider('invalidContributionFields')]
    public function testContributionWithAnInvalidFieldIsRejectedOnThatField(array $changes, array $expectedPropertyPaths): void
    {
        $this->assertSame($expectedPropertyPaths, $this->violatedPathsOfTheSales([$this->contribution($changes)]));
    }

    public static function invalidContributionFields(): iterable
    {
        yield 'project id of thirty-seven characters' => [['projectId' => self::PROJECT_ID . 'f'], ['sales[0][projectId]']];
        yield 'quantity as text' => [['quantity' => '600'], ['sales[0][quantity]']];
        yield 'decimal quantity' => [['quantity' => 600.5], ['sales[0][quantity]']];
        yield 'accounted quantity as text' => [['accountedQuantity' => '1000'], ['sales[0][accountedQuantity]']];
        yield 'decimal accounted quantity' => [['accountedQuantity' => 1000.5], ['sales[0][accountedQuantity]']];
        yield 'character id as text' => [['characterId' => '2112000001'], ['sales[0][characterId]']];
        yield 'decimal character id' => [['characterId' => 2112000001.5], ['sales[0][characterId]']];
        yield 'null price of a contribution of unknown reward' => [['price' => null], ['sales[0][price]']];
    }

    public function testContributionWithAProjectIdOfThirtySixCharactersIsAccepted(): void
    {
        $this->assertSame(36, strlen(self::PROJECT_ID));
        $this->assertSame([], $this->violatedPathsOfTheSales([$this->contribution(['projectId' => self::PROJECT_ID])]));
    }

    public function testFiveHundredSalesAreAccepted(): void
    {
        $this->assertSame([], $this->violatedPathsOfTheSales(array_fill(0, self::MAXIMUM_ITEMS, $this->marketLootSale())));
    }

    public function testFiveHundredAndOneSalesAreRejectedOnTheSales(): void
    {
        $this->assertSame(['sales'], $this->violatedPathsOfTheSales(array_fill(0, self::MAXIMUM_ITEMS + 1, $this->marketLootSale())));
    }

    public function testDeclinedMarketSaleSentByTheScanIsAccepted(): void
    {
        $this->assertSame([], $this->violatedPathsOfTheDeclined([['transactionId' => 8200023]]));
    }

    public function testDeclinedContributionSentByTheScanIsAccepted(): void
    {
        $this->assertSame([], $this->violatedPathsOfTheDeclined([$this->declinedContribution()]));
    }

    #[DataProvider('invalidDeclinedFields')]
    public function testDeclinedItemWithAnInvalidFieldIsRejectedOnThatField(array $declinedItem, array $expectedPropertyPaths): void
    {
        $this->assertSame($expectedPropertyPaths, $this->violatedPathsOfTheDeclined([['transactionId' => 8200023], $declinedItem]));
    }

    public static function invalidDeclinedFields(): iterable
    {
        yield 'project id of thirty-seven characters' => [self::declinedContributionWith(['projectId' => self::PROJECT_ID . 'f']), ['declined[1][projectId]']];
        yield 'quantity as text' => [self::declinedContributionWith(['quantity' => '600']), ['declined[1][quantity]']];
        yield 'accounted quantity as text' => [self::declinedContributionWith(['accountedQuantity' => '1000']), ['declined[1][accountedQuantity]']];
        yield 'character id as text' => [self::declinedContributionWith(['characterId' => '2112000001']), ['declined[1][characterId]']];
        yield 'transaction id as text' => [['transactionId' => '8200023'], ['declined[1][transactionId]']];
    }

    public function testFiveHundredDeclinedItemsAreAccepted(): void
    {
        $this->assertSame([], $this->violatedPathsOfTheDeclined(array_fill(0, self::MAXIMUM_ITEMS, ['transactionId' => 8200023])));
    }

    public function testFiveHundredAndOneDeclinedItemsAreRejectedOnTheDeclined(): void
    {
        $this->assertSame(['declined'], $this->violatedPathsOfTheDeclined(array_fill(0, self::MAXIMUM_ITEMS + 1, ['transactionId' => 8200023])));
    }

    public function testInputWithAnInvalidSaleAndAnInvalidDeclinedItemIsRejectedOnBoth(): void
    {
        $input = new ImportLootSalesInput();
        $input->sales = [$this->marketLootSale(['price' => 0])];
        $input->declined = [self::declinedContributionWith(['projectId' => self::PROJECT_ID . 'f'])];

        $propertyPaths = $this->uniquePropertyPathsOf($this->validator->validate($input));
        sort($propertyPaths);

        $this->assertSame(['declined[0][projectId]', 'sales[0][price]'], $propertyPaths);
    }

    private function marketLootSale(array $changes = []): array
    {
        return self::marketLootSaleWith($changes);
    }

    private static function marketLootSaleWith(array $changes): array
    {
        return array_merge([
            'type' => 'loot_sale',
            'typeId' => self::ROGUE_DRONE_INFESTATION_DATA,
            'typeName' => '6x Rogue Drone Infestation Data',
            'quantity' => 6,
            'price' => 2460000.0,
            'dateIssued' => '2026-10-28',
            'transactionId' => 8200031,
        ], $changes);
    }

    private static function marketLootSaleWithout(string $field): array
    {
        $sale = self::marketLootSaleWith([]);
        unset($sale[$field]);

        return $sale;
    }

    private function contribution(array $changes = []): array
    {
        return array_merge([
            'type' => 'corp_project',
            'typeId' => self::TRITANIUM,
            'typeName' => 'Tritanium Drive: 600x Tritanium',
            'quantity' => 600,
            'price' => 7500.0,
            'dateIssued' => '2026-10-28',
            'transactionId' => -abs(crc32(self::PROJECT_ID . '_' . self::PILOT_ONE_ID . '_1600')),
            'projectId' => self::PROJECT_ID,
            'characterId' => self::PILOT_ONE_ID,
            'accountedQuantity' => 1000,
        ], $changes);
    }

    private function declinedContribution(): array
    {
        return self::declinedContributionWith([]);
    }

    private static function declinedContributionWith(array $changes): array
    {
        return array_merge([
            'type' => 'corp_project',
            'transactionId' => -abs(crc32(self::PROJECT_ID . '_' . self::PILOT_ONE_ID . '_1600')),
            'projectId' => self::PROJECT_ID,
            'characterId' => self::PILOT_ONE_ID,
            'quantity' => 600,
            'accountedQuantity' => 1000,
        ], $changes);
    }

    private function violatedPathsOfTheSales(array $sales): array
    {
        return $this->uniquePropertyPathsOf($this->validator->validatePropertyValue(new ImportLootSalesInput(), 'sales', $sales));
    }

    private function violatedPathsOfTheDeclined(array $declined): array
    {
        return $this->uniquePropertyPathsOf($this->validator->validatePropertyValue(new ImportLootSalesInput(), 'declined', $declined));
    }
}
