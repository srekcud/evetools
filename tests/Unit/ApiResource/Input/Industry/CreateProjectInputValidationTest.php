<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\CreateProjectInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreateProjectInput::class)]
final class CreateProjectInputValidationTest extends TestCase
{
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int MAXIMUM_EXCLUDED_COMPONENTS = 500;
    private const int FIRST_TYPE_ID = 34;
    private const int MAXIMUM_PROJECT_RUNS = 100000;
    private const int PROJECT_RUNS_ABOVE_THE_MAXIMUM = 100001;
    private const float MINIMUM_MAX_JOB_DURATION_DAYS = 0.5;
    private const float MAX_JOB_DURATION_DAYS_BELOW_THE_MINIMUM = 0.4;

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

    #[DataProvider('excludedComponentsThatAreNotIntegers')]
    public function testExcludedComponentThatIsNotAnIntegerIsRejected(mixed $excludedComponent): void
    {
        $violations = $this->validateExcludedTypeIds([$excludedComponent]);

        self::assertSame(['excludedTypeIds[0]'], array_values(array_unique($this->propertyPathsOf($violations))));
    }

    public static function excludedComponentsThatAreNotIntegers(): \Generator
    {
        yield 'text' => ['abc'];
        yield 'decimal' => [1.5];
        yield 'numeric text' => ['16671'];
    }

    public function testMoreExcludedComponentsThanTheMaximumAreRejected(): void
    {
        $violations = $this->validateExcludedTypeIds(range(self::FIRST_TYPE_ID, self::FIRST_TYPE_ID + self::MAXIMUM_EXCLUDED_COMPONENTS));

        self::assertSame(['excludedTypeIds'], $this->propertyPathsOf($violations));
    }

    public function testTheMaximumNumberOfExcludedComponentsIsAccepted(): void
    {
        $violations = $this->validateExcludedTypeIds(range(self::FIRST_TYPE_ID, self::FIRST_TYPE_ID + self::MAXIMUM_EXCLUDED_COMPONENTS - 1));

        self::assertCount(0, $violations);
    }

    #[Group('issue-21')]
    public function testTheMaximumProjectRunsAreAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateProjectInput(), 'runs', self::MAXIMUM_PROJECT_RUNS);

        self::assertCount(0, $violations);
    }

    #[Group('issue-21')]
    public function testProjectRunsAboveTheMaximumAreRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateProjectInput(), 'runs', self::PROJECT_RUNS_ABOVE_THE_MAXIMUM);

        self::assertSame(['runs'], $this->propertyPathsOf($violations));
    }

    #[Group('issue-21')]
    public function testTheMinimumMaxJobDurationIsAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateProjectInput(), 'maxJobDurationDays', self::MINIMUM_MAX_JOB_DURATION_DAYS);

        self::assertCount(0, $violations);
    }

    #[Group('issue-21')]
    public function testMaxJobDurationBelowTheMinimumIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateProjectInput(), 'maxJobDurationDays', self::MAX_JOB_DURATION_DAYS_BELOW_THE_MINIMUM);

        self::assertSame(['maxJobDurationDays'], $this->propertyPathsOf($violations));
    }

    #[Group('issue-200')]
    public function testOddProjectTeLevelIsRejectedWithTheParityMessage(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateProjectInput(), 'teLevel', 7);

        self::assertCount(1, $violations);
        self::assertSame('teLevel', $violations[0]->getPropertyPath());
        self::assertSame('TE must be an even level between 0 and 20.', $violations[0]->getMessage());
    }

    #[Group('issue-200')]
    public function testEvenProjectTeLevelIsAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateProjectInput(), 'teLevel', 10);

        self::assertCount(0, $violations);
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
