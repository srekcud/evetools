<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\UpdateUserSettingsInput;
use App\Tests\Unit\ApiResource\Input\GroupIndustry\PropertyPathsOfViolations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(UpdateUserSettingsInput::class)]
#[Group('issue-200')]
final class UpdateUserSettingsInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const int JITA_SOLAR_SYSTEM_ID = 30000142;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[DataProvider('settingsSentAlone')]
    public function testSettingSentAloneIsValidatedOnItsOwnPath(string $setting, float $value, array $expectedPropertyPaths): void
    {
        $input = new UpdateUserSettingsInput();
        $input->{$setting} = $value;

        self::assertSame($expectedPropertyPaths, $this->uniquePropertyPathsOf($this->validator->validate($input)));
    }

    public static function settingsSentAlone(): iterable
    {
        yield 'broker fee rate of -5' => ['brokerFeeRate', -5.0, ['brokerFeeRate']];
        yield 'sales tax rate of -0.0001' => ['salesTaxRate', -0.0001, ['salesTaxRate']];
        yield 'broker fee rate of zero' => ['brokerFeeRate', 0.0, []];
        yield 'sales tax rate of 0.036' => ['salesTaxRate', 0.036, []];
        yield 'broker fee rate of one' => ['brokerFeeRate', 1.0, []];
        yield 'sales tax rate of 1.0001' => ['salesTaxRate', 1.0001, ['salesTaxRate']];
        yield 'broker fee rate of 3.6 sent as a percent' => ['brokerFeeRate', 3.6, ['brokerFeeRate']];
        yield 'sales tax rate of 100' => ['salesTaxRate', 100.0, ['salesTaxRate']];
        yield 'export cost per m3 of -1500' => ['exportCostPerM3', -1500.0, ['exportCostPerM3']];
        yield 'export cost per m3 of zero' => ['exportCostPerM3', 0.0, []];
    }

    public function testOnlyTheOutOfRangeBrokerFeeRateIsRejectedWhenSentWithAValidSalesTaxRate(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->brokerFeeRate = 1.5;
        $input->salesTaxRate = 0.02;

        self::assertSame(['brokerFeeRate'], $this->uniquePropertyPathsOf($this->validator->validate($input)));
    }

    public function testFavoriteManufacturingSystemSentAloneIsAccepted(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->favoriteManufacturingSystemId = self::JITA_SOLAR_SYSTEM_ID;

        self::assertCount(0, $this->validator->validate($input));
    }
}
