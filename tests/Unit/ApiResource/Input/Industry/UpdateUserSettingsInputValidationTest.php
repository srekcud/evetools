<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\UpdateUserSettingsInput;
use App\Tests\Unit\ApiResource\Input\Industry\Project\PropertyPathsOfViolations;
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
        yield 'export cost per m3 of -1500' => ['exportCostPerM3', -1500.0, ['exportCostPerM3']];
        yield 'export cost per m3 of zero' => ['exportCostPerM3', 0.0, []];
        yield 'jump bridge cost per m3 of -1' => ['jumpBridgeCostPerM3', -1.0, ['jumpBridgeCostPerM3']];
        yield 'jump bridge cost per m3 of zero' => ['jumpBridgeCostPerM3', 0.0, []];
        yield 'jump bridge cost per m3 of 50' => ['jumpBridgeCostPerM3', 50.0, []];
    }

    #[Group('issue-1286')]
    #[DataProvider('courierHubsSentAlone')]
    public function testCourierHubSentAloneIsValidatedOnItsOwnPath(int $solarSystemId, array $expectedPropertyPaths): void
    {
        $input = new UpdateUserSettingsInput();
        $input->courierHubSolarSystemId = $solarSystemId;

        self::assertSame($expectedPropertyPaths, $this->uniquePropertyPathsOf($this->validator->validate($input)));
    }

    public static function courierHubsSentAlone(): iterable
    {
        yield 'Jita as the hub' => [self::JITA_SOLAR_SYSTEM_ID, []];
        yield 'zero' => [0, ['courierHubSolarSystemId']];
        yield 'negative identifier' => [-30_000_772, ['courierHubSolarSystemId']];
        yield 'largest int4' => [2_147_483_647, []];
        yield 'first value beyond int4' => [2_147_483_648, ['courierHubSolarSystemId']];
    }

    public function testFavoriteManufacturingSystemSentAloneIsAccepted(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->favoriteManufacturingSystemId = self::JITA_SOLAR_SYSTEM_ID;

        self::assertCount(0, $this->validator->validate($input));
    }
}
