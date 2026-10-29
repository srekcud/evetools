<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\CreateStepInput;
use App\Tests\Unit\ApiResource\Input\GroupIndustry\PropertyPathsOfViolations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreateStepInput::class)]
#[Group('issue-200')]
final class CreateStepInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const string ODD_TE_MESSAGE = 'TE must be an even level between 0 and 20.';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testOddTeLevelIsRejectedWithTheParityMessage(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateStepInput(), 'teLevel', 1);

        self::assertCount(1, $violations);
        self::assertSame('teLevel', $violations[0]->getPropertyPath());
        self::assertSame(self::ODD_TE_MESSAGE, $violations[0]->getMessage());
    }

    public function testEvenTeLevelIsAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateStepInput(), 'teLevel', 16);

        self::assertCount(0, $violations);
    }
}
