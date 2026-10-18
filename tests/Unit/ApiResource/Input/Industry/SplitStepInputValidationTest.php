<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\SplitStepInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(SplitStepInput::class)]
final class SplitStepInputValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testManualSplitIntoOneJobIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new SplitStepInput(), 'numberOfJobs', 1);

        self::assertCount(1, $violations);
        self::assertSame('numberOfJobs', $violations[0]->getPropertyPath());
    }

    public function testManualSplitIntoTwoJobsIsAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new SplitStepInput(), 'numberOfJobs', 2);

        self::assertCount(0, $violations);
    }
}
