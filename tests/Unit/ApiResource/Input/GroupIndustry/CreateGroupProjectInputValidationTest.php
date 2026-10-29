<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\GroupIndustry;

use App\ApiResource\Input\GroupIndustry\CreateGroupProjectInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreateGroupProjectInput::class)]
#[Group('issue-164')]
final class CreateGroupProjectInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const int ISHTAR_TYPE_ID = 12005;
    private const int SABRE_TYPE_ID = 22456;
    private const int FENRIR_TYPE_ID = 20189;
    private const int SCORPION_TYPE_ID = 640;
    private const int MAXIMUM_TYPE_NAME_LENGTH = 255;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testIshtarItemAtTheHighestMaterialAndTimeEfficiencyIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf([$this->ishtarItem()]));
    }

    public function testItemWithZeroRunsIsRejectedOnItsRuns(): void
    {
        self::assertSame(['items[0][runs]'], $this->violatedPathsOf([$this->ishtarItem(['runs' => 0])]));
    }

    public function testItemWithNegativeRunsIsRejectedOnItsRuns(): void
    {
        self::assertSame(['items[0][runs]'], $this->violatedPathsOf([$this->ishtarItem(['runs' => -5])]));
    }

    public function testItemWithOneRunIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf([$this->ishtarItem(['runs' => 1])]));
    }

    public function testItemWithOneHundredThousandRunsIsAcceptedByTheInputValidation(): void
    {
        self::assertSame([], $this->violatedPathsOf([$this->ishtarItem(['runs' => 100000])]));
    }

    #[Group('issue-257')]
    public function testItemWithOneHundredThousandAndOneRunsIsRejectedOnItsRuns(): void
    {
        self::assertSame(['items[0][runs]'], $this->violatedPathsOf([$this->fenrirItem(['runs' => 100001])]));
    }

    #[Group('issue-257')]
    public function testItemWithRunsBeyondTheIntegerColumnIsRejectedOnItsRuns(): void
    {
        self::assertSame(['items[0][runs]'], $this->violatedPathsOf([$this->fenrirItem(['runs' => 2147483648])]));
    }

    #[Group('issue-257')]
    public function testOnlyItemWithOneHundredThousandAndOneRunsIsRejectedOnItsRunsAndOnTheSumOfTheRuns(): void
    {
        self::assertSame(['items', 'items[0][runs]'], $this->violatedPathsOfTheInputWithItems([$this->fenrirItem(['runs' => 100001])]));
    }

    #[Group('issue-257')]
    public function testSecondItemWithOneHundredThousandAndOneRunsIsRejectedOnItsRunsAndOnTheSumOfTheRuns(): void
    {
        self::assertSame(
            ['items', 'items[1][runs]'],
            $this->violatedPathsOfTheInputWithItems([$this->fenrirItem(['runs' => 1]), $this->fenrirItem(['runs' => 100001])]),
        );
    }

    #[Group('issue-257')]
    public function testOnlyItemWithOneHundredThousandRunsIsAcceptedWithTheSumOfTheRuns(): void
    {
        self::assertSame([], $this->violatedPathsOfTheInputWithItems([$this->fenrirItem(['runs' => 100000])]));
    }

    #[Group('issue-257')]
    public function testTenItemsOfFenrirSummingToOneHundredThousandRunsAreAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOfTheInputWithItems(array_fill(0, 10, $this->fenrirItem(['runs' => 10000]))));
    }

    #[Group('issue-257')]
    public function testTenItemsOfFenrirSummingToOneHundredThousandAndTenRunsAreRejectedOnTheItemsOnly(): void
    {
        self::assertSame(['items'], $this->violatedPathsOfTheInputWithItems(array_fill(0, 10, $this->fenrirItem(['runs' => 10001]))));
    }

    #[Group('issue-257')]
    public function testItemsOfTwoProductsSummingToOneHundredThousandAndOneRunsAreRejectedOnTheItems(): void
    {
        self::assertSame(
            ['items'],
            $this->violatedPathsOfTheInputWithItems([$this->fenrirItem(['runs' => 60000]), $this->scorpionItem(['runs' => 40001])]),
        );
    }

    #[Group('issue-257')]
    public function testItemsOfTwoProductsSummingToExactlyOneHundredThousandRunsAreAccepted(): void
    {
        self::assertSame(
            [],
            $this->violatedPathsOfTheInputWithItems([$this->fenrirItem(['runs' => 60000]), $this->scorpionItem(['runs' => 40000])]),
        );
    }

    #[Group('issue-257')]
    public function testThreeItemsOfOneRunEachAreAccepted(): void
    {
        self::assertSame(
            [],
            $this->violatedPathsOfTheInputWithItems([$this->fenrirItem(['runs' => 1]), $this->scorpionItem(['runs' => 1]), $this->ishtarItem(['runs' => 1])]),
        );
    }

    #[Group('issue-257')]
    #[DataProvider('itemsWhoseRunsAreNotAPositiveIntegerBesideOneHundredThousandRuns')]
    public function testItemWhoseRunsAreNotAPositiveIntegerAddsNoViolationOnTheSumOfTheRuns(array $items, array $expectedPropertyPaths): void
    {
        self::assertSame($expectedPropertyPaths, $this->violatedPathsOfTheInputWithItems($items));
    }

    public static function itemsWhoseRunsAreNotAPositiveIntegerBesideOneHundredThousandRuns(): iterable
    {
        $fenrir = ['typeId' => self::FENRIR_TYPE_ID, 'typeName' => 'Fenrir', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 100000];
        $scorpion = ['typeId' => self::SCORPION_TYPE_ID, 'typeName' => 'Scorpion', 'meLevel' => 8, 'teLevel' => 16];

        yield 'runs given as text' => [[$fenrir, $scorpion + ['runs' => '5']], ['items[1][runs]']];
        yield 'decimal runs beside ninety-nine thousand nine hundred ninety-nine runs' => [[['runs' => 99999] + $fenrir, $scorpion + ['runs' => 2.5]], ['items[1][runs]']];
        yield 'runs missing' => [[$fenrir, $scorpion], ['items[1][runs]']];
        yield 'item that is not an object' => [[$fenrir, 'x'], ['items[1]']];
        yield 'negative runs beside one hundred thousand and one runs' => [[['runs' => 100001] + $fenrir, $scorpion + ['runs' => -5]], ['items', 'items[0][runs]', 'items[1][runs]']];
    }

    #[Group('issue-257')]
    #[DataProvider('blacklistsOfTheMaximumSize')]
    public function testBlacklistOfTwoHundredEntriesIsAccepted(string $blacklist, array $entries): void
    {
        self::assertSame([], $this->violatedPathsOfTheBlacklist($blacklist, $entries));
    }

    public static function blacklistsOfTheMaximumSize(): iterable
    {
        yield 'type ids' => ['blacklistTypeIds', range(30001, 30200)];
        yield 'group ids' => ['blacklistGroupIds', range(401, 600)];
        yield 'category keys' => ['blacklistCategoryKeys', array_map(static fn (int $index): string => "category_{$index}", range(1, 200))];
    }

    #[Group('issue-257')]
    #[DataProvider('blacklistsOverTheMaximumSize')]
    public function testBlacklistOfTwoHundredAndOneEntriesIsRejectedOnThatBlacklist(string $blacklist, array $entries): void
    {
        self::assertSame([$blacklist], $this->violatedPathsOfTheBlacklist($blacklist, $entries));
    }

    public static function blacklistsOverTheMaximumSize(): iterable
    {
        yield 'type ids' => ['blacklistTypeIds', range(30001, 30201)];
        yield 'group ids' => ['blacklistGroupIds', range(401, 601)];
        yield 'category keys' => ['blacklistCategoryKeys', array_map(static fn (int $index): string => "category_{$index}", range(1, 201))];
    }

    #[Group('issue-257')]
    public function testGroupAndCategoryBlacklistsOfTwoHundredAndOneEntriesAreEachRejected(): void
    {
        $input = $this->inputWithItems([$this->fenrirItem()]);
        $input->blacklistGroupIds = range(401, 601);
        $input->blacklistTypeIds = range(30001, 30200);
        $input->blacklistCategoryKeys = array_map(static fn (int $index): string => "category_{$index}", range(1, 201));

        self::assertSame(['blacklistCategoryKeys', 'blacklistGroupIds'], $this->violatedPathsOfTheInput($input));
    }

    #[Group('issue-257')]
    public function testEmptyBlacklistsAreAccepted(): void
    {
        $input = $this->inputWithItems([$this->fenrirItem()]);
        $input->blacklistGroupIds = [];
        $input->blacklistTypeIds = [];
        $input->blacklistCategoryKeys = [];

        self::assertSame([], $this->violatedPathsOfTheInput($input));
    }

    #[Group('issue-257')]
    #[DataProvider('feeValues')]
    public function testFeeIsAPercentFromZeroToOneHundred(string $fee, ?float $percent, array $expectedPropertyPaths): void
    {
        self::assertSame($expectedPropertyPaths, $this->uniquePropertyPathsOf($this->validator->validatePropertyValue(new CreateGroupProjectInput(), $fee, $percent)));
    }

    public static function feeValues(): iterable
    {
        yield 'broker fee of zero' => ['brokerFeePercent', 0.0, []];
        yield 'broker fee of 3.6' => ['brokerFeePercent', 3.6, []];
        yield 'broker fee of one hundred' => ['brokerFeePercent', 100.0, []];
        yield 'broker fee of 100.01' => ['brokerFeePercent', 100.01, ['brokerFeePercent']];
        yield 'broker fee of -0.01' => ['brokerFeePercent', -0.01, ['brokerFeePercent']];
        yield 'broker fee absent' => ['brokerFeePercent', null, []];
        yield 'sales tax of zero' => ['salesTaxPercent', 0.0, []];
        yield 'sales tax of 4.5' => ['salesTaxPercent', 4.5, []];
        yield 'sales tax of one hundred' => ['salesTaxPercent', 100.0, []];
        yield 'sales tax of 100.01' => ['salesTaxPercent', 100.01, ['salesTaxPercent']];
        yield 'sales tax of -0.01' => ['salesTaxPercent', -0.01, ['salesTaxPercent']];
        yield 'sales tax absent' => ['salesTaxPercent', null, []];
    }

    #[Group('issue-257')]
    #[DataProvider('feesSentTogether')]
    public function testSumOfTheFeesSentTogetherIsAtMostOneHundredOnTheSalesTax(float $brokerFeePercent, float $salesTaxPercent, array $expectedPropertyPaths): void
    {
        $input = $this->inputWithItems([$this->fenrirItem()]);
        $input->brokerFeePercent = $brokerFeePercent;
        $input->salesTaxPercent = $salesTaxPercent;

        self::assertSame($expectedPropertyPaths, $this->violatedPathsOfTheInput($input));
    }

    public static function feesSentTogether(): iterable
    {
        yield 'broker fee of one hundred and no sales tax' => [100.0, 0.0, []];
        yield 'no broker fee and a sales tax of one hundred' => [0.0, 100.0, []];
        yield 'sixty and forty' => [60.0, 40.0, []];
        yield 'sixty and 40.01' => [60.0, 40.01, ['salesTaxPercent']];
        yield '40.01 and sixty' => [40.01, 60.0, ['salesTaxPercent']];
    }

    #[Group('issue-257')]
    public function testBrokerFeeOfOneHundredSentWithoutSalesTaxIsLeftToTheProcessor(): void
    {
        $input = $this->inputWithItems([$this->fenrirItem()]);
        $input->brokerFeePercent = 100.0;

        self::assertSame([], $this->violatedPathsOfTheInput($input));
    }

    public function testItemWithDecimalRunsIsRejectedOnItsRuns(): void
    {
        self::assertSame(['items[0][runs]'], $this->violatedPathsOf([$this->ishtarItem(['runs' => 2.5])]));
    }

    public function testItemWithMaterialEfficiencyAboveTenIsRejectedOnItsMaterialEfficiency(): void
    {
        self::assertSame(['items[0][meLevel]'], $this->violatedPathsOf([$this->ishtarItem(['meLevel' => 11])]));
    }

    public function testItemWithNegativeMaterialEfficiencyIsRejectedOnItsMaterialEfficiency(): void
    {
        self::assertSame(['items[0][meLevel]'], $this->violatedPathsOf([$this->ishtarItem(['meLevel' => -1])]));
    }

    public function testItemWithTimeEfficiencyAboveTwentyIsRejectedOnItsTimeEfficiency(): void
    {
        self::assertSame(['items[0][teLevel]'], $this->violatedPathsOf([$this->ishtarItem(['teLevel' => 21])]));
    }

    public function testItemWithNegativeEvenTimeEfficiencyIsRejectedOnItsTimeEfficiency(): void
    {
        self::assertSame(['items[0][teLevel]'], $this->violatedPathsOf([$this->ishtarItem(['teLevel' => -2])]));
    }

    public function testItemWithoutAnyResearchIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf([$this->ishtarItem(['meLevel' => 0, 'teLevel' => 0])]));
    }

    public function testItemWithAnOddTimeEfficiencyInsideTheRangePassesTheInputValidation(): void
    {
        self::assertSame([], $this->violatedPathsOf([$this->ishtarItem(['teLevel' => 15])]));
    }

    #[DataProvider('itemKeys')]
    public function testItemWithoutOneOfItsFiveKeysIsRejectedOnThatKey(string $missingKey): void
    {
        $item = $this->ishtarItem();
        unset($item[$missingKey]);

        self::assertSame(["items[0][{$missingKey}]"], $this->violatedPathsOf([$item]));
    }

    public static function itemKeys(): iterable
    {
        yield 'typeId' => ['typeId'];
        yield 'typeName' => ['typeName'];
        yield 'meLevel' => ['meLevel'];
        yield 'teLevel' => ['teLevel'];
        yield 'runs' => ['runs'];
    }

    #[DataProvider('itemKeys')]
    public function testItemWithANullValueIsRejectedOnThatKey(string $nullKey): void
    {
        self::assertSame(["items[0][{$nullKey}]"], $this->violatedPathsOf([$this->ishtarItem([$nullKey => null])]));
    }

    #[DataProvider('itemsThatAreNotObjects')]
    public function testItemThatIsNotAnObjectIsRejectedOnTheItem(mixed $item): void
    {
        self::assertSame(['items[1]'], $this->violatedPathsOf([$this->ishtarItem(), $item]));
    }

    public static function itemsThatAreNotObjects(): iterable
    {
        yield 'text' => ['x'];
        yield 'number' => [12005];
        yield 'null' => [null];
    }

    public function testItemPastedWithTypeIdZeroToBeResolvedByItsNameIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf([$this->ishtarItem(['typeId' => 0])]));
    }

    public function testItemWithNegativeTypeIdIsRejectedOnItsTypeId(): void
    {
        self::assertSame(['items[0][typeId]'], $this->violatedPathsOf([$this->ishtarItem(['typeId' => -3])]));
    }

    public function testItemWithTypeIdGivenAsTextIsRejectedOnItsTypeId(): void
    {
        self::assertSame(['items[0][typeId]'], $this->violatedPathsOf([$this->ishtarItem(['typeId' => '12005'])]));
    }

    public function testItemWithEmptyTypeNameIsRejectedOnItsTypeName(): void
    {
        self::assertSame(['items[0][typeName]'], $this->violatedPathsOf([$this->ishtarItem(['typeName' => ''])]));
    }

    public function testItemWithTypeNameOfTheMaximumLengthIsAccepted(): void
    {
        $typeName = str_repeat('I', self::MAXIMUM_TYPE_NAME_LENGTH);

        self::assertSame([], $this->violatedPathsOf([$this->ishtarItem(['typeName' => $typeName])]));
    }

    public function testItemWithTypeNameLongerThanTheMaximumIsRejectedOnItsTypeName(): void
    {
        $typeName = str_repeat('I', self::MAXIMUM_TYPE_NAME_LENGTH + 1);

        self::assertSame(['items[0][typeName]'], $this->violatedPathsOf([$this->ishtarItem(['typeName' => $typeName])]));
    }

    public function testSecondItemOfAnotherProductWithZeroRunsIsRejectedOnTheRunsOfTheSecondItem(): void
    {
        $sabreItem = ['typeId' => self::SABRE_TYPE_ID, 'typeName' => 'Sabre', 'meLevel' => 8, 'teLevel' => 16, 'runs' => 0];

        self::assertSame(['items[1][runs]'], $this->violatedPathsOf([$this->ishtarItem(), $sabreItem]));
    }

    public function testPastedIshtarLineWithEveryValueOutOfRangeIsRejectedOnEachOfThem(): void
    {
        $pastedIshtar = $this->ishtarItem(['typeId' => 0, 'meLevel' => 12, 'teLevel' => 25, 'runs' => 0]);

        self::assertSame(
            ['items[0][meLevel]', 'items[0][runs]', 'items[0][teLevel]'],
            $this->sorted($this->violatedPathsOf([$pastedIshtar])),
        );
    }

    public function testSecondItemOfTheSameProductAtTheSameEfficiencyWithZeroRunsIsRejectedOnItsRuns(): void
    {
        self::assertSame(['items[1][runs]'], $this->violatedPathsOf([$this->ishtarItem(), $this->ishtarItem(['runs' => 0])]));
    }

    public function testItemWithAQuantityKeyBesideItsRunsIsRejectedOnTheExtraKey(): void
    {
        self::assertSame(['items[0][quantity]'], $this->violatedPathsOf([$this->ishtarItem(['quantity' => 5])]));
    }

    private function ishtarItem(array $changes = []): array
    {
        return array_merge(
            ['typeId' => self::ISHTAR_TYPE_ID, 'typeName' => 'Ishtar', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            $changes,
        );
    }

    private function fenrirItem(array $changes = []): array
    {
        return array_merge(
            ['typeId' => self::FENRIR_TYPE_ID, 'typeName' => 'Fenrir', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 1],
            $changes,
        );
    }

    private function scorpionItem(array $changes = []): array
    {
        return array_merge(
            ['typeId' => self::SCORPION_TYPE_ID, 'typeName' => 'Scorpion', 'meLevel' => 8, 'teLevel' => 16, 'runs' => 1],
            $changes,
        );
    }

    private function violatedPathsOf(array $items): array
    {
        return $this->uniquePropertyPathsOf($this->validator->validatePropertyValue(new CreateGroupProjectInput(), 'items', $items));
    }

    private function inputWithItems(array $items): CreateGroupProjectInput
    {
        $input = new CreateGroupProjectInput();
        $input->items = $items;

        return $input;
    }

    private function violatedPathsOfTheInput(CreateGroupProjectInput $input): array
    {
        return $this->sorted($this->uniquePropertyPathsOf($this->validator->validate($input)));
    }

    private function violatedPathsOfTheInputWithItems(array $items): array
    {
        return $this->violatedPathsOfTheInput($this->inputWithItems($items));
    }

    private function violatedPathsOfTheBlacklist(string $blacklist, array $entries): array
    {
        return $this->uniquePropertyPathsOf($this->validator->validatePropertyValue(new CreateGroupProjectInput(), $blacklist, $entries));
    }

    private function sorted(array $propertyPaths): array
    {
        sort($propertyPaths);

        return $propertyPaths;
    }
}
