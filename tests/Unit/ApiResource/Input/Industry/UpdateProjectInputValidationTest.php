<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\UpdateProjectInput;
use App\Enum\ProjectStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
    private const string UNKNOWN_PROJECT_STATUS = 'bogus';
    private const int MAXIMUM_PROJECT_RUNS = 100000;
    private const int PROJECT_RUNS_ABOVE_THE_MAXIMUM = 100001;
    private const float MINIMUM_MAX_JOB_DURATION_DAYS = 0.5;
    private const float MAX_JOB_DURATION_DAYS_BELOW_THE_MINIMUM = 0.4;

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

    #[Group('issue-21')]
    public function testUnknownProjectStatusIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateProjectInput(), 'status', self::UNKNOWN_PROJECT_STATUS);

        self::assertCount(1, $violations);
        self::assertSame('status', $violations[0]->getPropertyPath());
    }

    #[Group('issue-21')]
    #[DataProvider('projectStatuses')]
    public function testEveryProjectStatusIsAccepted(ProjectStatus $projectStatus): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateProjectInput(), 'status', $projectStatus->value);

        self::assertCount(0, $violations);
    }

    #[Group('issue-21')]
    public function testPatchingTheMaximumProjectRunsIsAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateProjectInput(), 'runs', self::MAXIMUM_PROJECT_RUNS);

        self::assertCount(0, $violations);
    }

    #[Group('issue-21')]
    public function testPatchingProjectRunsAboveTheMaximumIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateProjectInput(), 'runs', self::PROJECT_RUNS_ABOVE_THE_MAXIMUM);

        self::assertSame(['runs'], $this->propertyPathsOf($violations));
    }

    #[Group('issue-21')]
    public function testPatchingTheMinimumMaxJobDurationIsAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateProjectInput(), 'maxJobDurationDays', self::MINIMUM_MAX_JOB_DURATION_DAYS);

        self::assertCount(0, $violations);
    }

    #[Group('issue-21')]
    public function testPatchingMaxJobDurationBelowTheMinimumIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateProjectInput(), 'maxJobDurationDays', self::MAX_JOB_DURATION_DAYS_BELOW_THE_MINIMUM);

        self::assertSame(['maxJobDurationDays'], $this->propertyPathsOf($violations));
    }

    public static function projectStatuses(): \Generator
    {
        foreach (ProjectStatus::cases() as $projectStatus) {
            yield $projectStatus->value => [$projectStatus];
        }
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
