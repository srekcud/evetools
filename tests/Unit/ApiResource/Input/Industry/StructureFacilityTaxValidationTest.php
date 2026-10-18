<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\CreateStructureInput;
use App\ApiResource\Input\Industry\UpdateStructureInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreateStructureInput::class)]
#[CoversClass(UpdateStructureInput::class)]
final class StructureFacilityTaxValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public static function outOfBoundsFacilityTaxProvider(): iterable
    {
        yield 'E6 creation above fifty percent' => [CreateStructureInput::class, 50.01];
        yield 'E7 creation negative' => [CreateStructureInput::class, -1.0];
        yield 'E10 patch above fifty percent' => [UpdateStructureInput::class, 60.0];
        yield 'patch negative' => [UpdateStructureInput::class, -0.5];
    }

    #[DataProvider('outOfBoundsFacilityTaxProvider')]
    public function testFacilityTaxOutsideZeroToFiftyPercentIsRejected(string $inputClass, float $facilityTaxRate): void
    {
        $violations = $this->validator->validatePropertyValue($inputClass, 'facilityTaxRate', $facilityTaxRate);

        self::assertCount(1, $violations);
        self::assertSame('facilityTaxRate', $violations[0]->getPropertyPath());
    }

    public static function acceptedFacilityTaxProvider(): iterable
    {
        yield 'E2 creation without facility tax' => [CreateStructureInput::class, null];
        yield 'E4 creation lower bound' => [CreateStructureInput::class, 0.0];
        yield 'E5 creation upper bound' => [CreateStructureInput::class, 50.0];
        yield 'E3 creation decimal' => [CreateStructureInput::class, 3.5];
        yield 'E9 patch without facility tax' => [UpdateStructureInput::class, null];
        yield 'E8 patch decimal' => [UpdateStructureInput::class, 7.5];
        yield 'patch upper bound' => [UpdateStructureInput::class, 50.0];
    }

    #[DataProvider('acceptedFacilityTaxProvider')]
    public function testFacilityTaxFromZeroToFiftyPercentIsAccepted(string $inputClass, ?float $facilityTaxRate): void
    {
        $violations = $this->validator->validatePropertyValue($inputClass, 'facilityTaxRate', $facilityTaxRate);

        self::assertCount(0, $violations);
    }
}
