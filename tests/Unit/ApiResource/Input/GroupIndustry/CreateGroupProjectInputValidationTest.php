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

    #[Group('issue-257')]
    public function testItemWithOneHundredThousandRunsCurrentlyPassesTheInputValidationIssue257(): void
    {
        self::assertSame([], $this->violatedPathsOf([$this->ishtarItem(['runs' => 100000])]));
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

    private function violatedPathsOf(array $items): array
    {
        return $this->uniquePropertyPathsOf($this->validator->validatePropertyValue(new CreateGroupProjectInput(), 'items', $items));
    }

    private function sorted(array $propertyPaths): array
    {
        sort($propertyPaths);

        return $propertyPaths;
    }
}
