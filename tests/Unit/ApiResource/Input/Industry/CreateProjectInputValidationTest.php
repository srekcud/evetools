<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\CreateProjectInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreateProjectInput::class)]
final class CreateProjectInputValidationTest extends TestCase
{
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testProjectWithoutExcludedComponentExcludesNone(): void
    {
        self::assertSame([], get_object_vars(new CreateProjectInput())['excludedTypeIds'] ?? null);
    }

    public function testExcludedComponentsWithPositiveTypeIdsAreAccepted(): void
    {
        $violations = $this->validateExcludedTypeIds([self::TITANIUM_CARBIDE, self::PHENOLIC_COMPOSITES]);

        self::assertCount(0, $violations);
    }

    public function testExcludedComponentWithTypeIdZeroIsRejected(): void
    {
        $violations = $this->validateExcludedTypeIds([0]);

        self::assertSame(['excludedTypeIds[0]'], $this->propertyPathsOf($violations));
    }

    public function testExcludedComponentWithNegativeTypeIdIsRejected(): void
    {
        $violations = $this->validateExcludedTypeIds([self::TITANIUM_CARBIDE, -5]);

        self::assertSame(['excludedTypeIds[1]'], $this->propertyPathsOf($violations));
    }

    private function validateExcludedTypeIds(array $excludedTypeIds): ConstraintViolationListInterface
    {
        return $this->validator->validatePropertyValue(new CreateProjectInput(), 'excludedTypeIds', $excludedTypeIds);
    }

    private function propertyPathsOf(ConstraintViolationListInterface $violations): array
    {
        $propertyPaths = [];
        foreach ($violations as $violation) {
            $propertyPaths[] = $violation->getPropertyPath();
        }

        return $propertyPaths;
    }
}
