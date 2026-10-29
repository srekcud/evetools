<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\GroupIndustry;

use App\ApiResource\Input\GroupIndustry\UpdateGroupProjectInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(UpdateGroupProjectInput::class)]
#[Group('issue-257')]
final class UpdateGroupProjectInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[DataProvider('feeValues')]
    public function testFeeSentAloneIsAPercentFromZeroToOneHundred(string $fee, ?float $percent, array $expectedPropertyPaths): void
    {
        $input = new UpdateGroupProjectInput();
        $input->{$fee} = $percent;

        self::assertSame($expectedPropertyPaths, $this->violatedPathsOf($input));
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
        yield 'sales tax of 2.5' => ['salesTaxPercent', 2.5, []];
        yield 'sales tax of one hundred' => ['salesTaxPercent', 100.0, []];
        yield 'sales tax of 100.01' => ['salesTaxPercent', 100.01, ['salesTaxPercent']];
        yield 'sales tax of -0.01' => ['salesTaxPercent', -0.01, ['salesTaxPercent']];
        yield 'sales tax absent' => ['salesTaxPercent', null, []];
    }

    #[DataProvider('feesSentTogether')]
    public function testSumOfTheFeesSentTogetherIsAtMostOneHundredOnTheSalesTax(float $brokerFeePercent, float $salesTaxPercent, array $expectedPropertyPaths): void
    {
        $input = new UpdateGroupProjectInput();
        $input->brokerFeePercent = $brokerFeePercent;
        $input->salesTaxPercent = $salesTaxPercent;

        self::assertSame($expectedPropertyPaths, $this->violatedPathsOf($input));
    }

    public static function feesSentTogether(): iterable
    {
        yield 'broker fee of one hundred and no sales tax' => [100.0, 0.0, []];
        yield 'no broker fee and a sales tax of one hundred' => [0.0, 100.0, []];
        yield 'sixty and forty' => [60.0, 40.0, []];
        yield 'sixty and 40.01' => [60.0, 40.01, ['salesTaxPercent']];
        yield '40.01 and sixty' => [40.01, 60.0, ['salesTaxPercent']];
    }

    public function testRenameWithoutAnyFeeIsAccepted(): void
    {
        $input = new UpdateGroupProjectInput();
        $input->name = 'Rorqual batch';

        self::assertSame([], $this->violatedPathsOf($input));
    }

    private function violatedPathsOf(UpdateGroupProjectInput $input): array
    {
        $propertyPaths = $this->uniquePropertyPathsOf($this->validator->validate($input));
        sort($propertyPaths);

        return $propertyPaths;
    }
}
