<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\UpdateStepInput;
use App\Tests\Unit\ApiResource\Input\GroupIndustry\PropertyPathsOfViolations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(UpdateStepInput::class)]
#[Group('issue-200')]
final class UpdateStepInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const string ODD_TE_MESSAGE = 'TE must be an even level between 0 and 20.';
    private const string TE_RANGE_MESSAGE = 'This value should be between 0 and 20.';
    private const int STEP_RUNS = 5;
    private const string STRUCTURE_CONFIG_ID = '0192f0c4-7b1e-7a3c-9d2e-200000000021';
    private const string RUNS_RANGE_MESSAGE = 'This value should be between 1 and 100000.';
    private const int MAXIMUM_STEP_RUNS = 100000;
    private const int STEP_RUNS_ABOVE_THE_MAXIMUM = 100001;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testOddTeLevelIsRejectedWithTheParityMessage(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'teLevel', 3);

        self::assertCount(1, $violations);
        self::assertSame('teLevel', $violations[0]->getPropertyPath());
        self::assertSame(self::ODD_TE_MESSAGE, $violations[0]->getMessage());
    }

    public function testOddTeLevelJustBelowTheMaximumIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'teLevel', 19);

        self::assertSame(['teLevel'], $this->uniquePropertyPathsOf($violations));
    }

    #[DataProvider('evenTeLevelsInRange')]
    public function testEvenTeLevelInRangeIsAccepted(int $teLevel): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'teLevel', $teLevel);

        self::assertCount(0, $violations);
    }

    public static function evenTeLevelsInRange(): iterable
    {
        yield 'TE 4' => [4];
        yield 'TE 0' => [0];
        yield 'TE 20' => [20];
    }

    #[DataProvider('evenTeLevelsOutOfRange')]
    public function testEvenTeLevelOutOfRangeKeepsTheSingleRangeViolation(int $teLevel): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'teLevel', $teLevel);

        self::assertCount(1, $violations);
        self::assertSame('teLevel', $violations[0]->getPropertyPath());
        self::assertSame(self::TE_RANGE_MESSAGE, $violations[0]->getMessage());
    }

    public static function evenTeLevelsOutOfRange(): iterable
    {
        yield 'TE 22' => [22];
        yield 'TE -2' => [-2];
    }

    public function testPatchWithoutTeLevelHasNoTeViolation(): void
    {
        $input = new UpdateStepInput();
        $input->runs = self::STEP_RUNS;

        self::assertCount(0, $this->validator->validate($input));
    }

    public function testStructureConfigIdThatIsNotAUuidIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'structureConfigId', 'rig-1');

        self::assertSame(['structureConfigId'], $this->uniquePropertyPathsOf($violations));
    }

    public function testStructureConfigIdThatIsAUuidV7IsAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'structureConfigId', self::STRUCTURE_CONFIG_ID);

        self::assertCount(0, $violations);
    }

    #[Group('issue-287')]
    public function testStepRunsAboveTheMaximumAreRejectedWithTheRangeMessage(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'runs', self::STEP_RUNS_ABOVE_THE_MAXIMUM);

        self::assertCount(1, $violations);
        self::assertSame(['runs', self::RUNS_RANGE_MESSAGE], [$violations[0]->getPropertyPath(), $violations[0]->getMessage()]);
    }

    #[Group('issue-287')]
    public function testTheMaximumStepRunsAreAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'runs', self::MAXIMUM_STEP_RUNS);

        self::assertCount(0, $violations);
    }

    #[Group('issue-287')]
    public function testPatchWithoutRunsHasNoRunsViolation(): void
    {
        $violations = $this->validator->validatePropertyValue(new UpdateStepInput(), 'runs', null);

        self::assertCount(0, $violations);
    }
}
