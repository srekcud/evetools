<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\UpdateProjectInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(UpdateProjectInput::class)]
final class UpdateProjectInputValidationTest extends TestCase
{
    private const int ROBOTICS_TYPE_ID = 9848;
    private const int DATACORE_QUANTITY = 4;
    private const string UNKNOWN_TYPE_NAME_PATH = 'inventionMaterials[0][typeName]';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testInventionMaterialWithNullTypeNameIsRejected(): void
    {
        $violations = $this->validateInventionMaterials([
            ['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => null, 'quantity' => self::DATACORE_QUANTITY],
        ]);

        self::assertContains(self::UNKNOWN_TYPE_NAME_PATH, $this->propertyPathsOf($violations));
    }

    public function testInventionMaterialWithEmptyTypeNameIsRejected(): void
    {
        $violations = $this->validateInventionMaterials([
            ['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => '', 'quantity' => self::DATACORE_QUANTITY],
        ]);

        self::assertContains(self::UNKNOWN_TYPE_NAME_PATH, $this->propertyPathsOf($violations));
    }

    public function testInventionMaterialWithTypeNameIsAccepted(): void
    {
        $violations = $this->validateInventionMaterials([
            ['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => 'Robotics', 'quantity' => self::DATACORE_QUANTITY],
        ]);

        self::assertCount(0, $violations);
    }

    public function testMissingInventionMaterialsIsAccepted(): void
    {
        $violations = $this->validateInventionMaterials(null);

        self::assertCount(0, $violations);
    }

    /**
     * @param list<array<string, mixed>>|null $inventionMaterials
     */
    private function validateInventionMaterials(?array $inventionMaterials): ConstraintViolationListInterface
    {
        return $this->validator->validatePropertyValue(new UpdateProjectInput(), 'inventionMaterials', $inventionMaterials);
    }

    /**
     * @return list<string>
     */
    private function propertyPathsOf(ConstraintViolationListInterface $violations): array
    {
        $propertyPaths = [];
        foreach ($violations as $violation) {
            $propertyPaths[] = $violation->getPropertyPath();
        }

        return $propertyPaths;
    }
}
