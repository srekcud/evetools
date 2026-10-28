<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\StockpileImportInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(StockpileImportInput::class)]
#[Group('issue-258')]
final class StockpileImportInputValidationTest extends TestCase
{
    private const int MAX_RUNS = 10_000;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testTenThousandRunsAreAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new StockpileImportInput(), 'runs', self::MAX_RUNS);

        self::assertCount(0, $violations);
    }

    public function testTenThousandAndOneRunsAreRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new StockpileImportInput(), 'runs', self::MAX_RUNS + 1);

        self::assertCount(1, $violations);
        self::assertSame('runs', $violations[0]->getPropertyPath());
    }

    public function testZeroRunsAreRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new StockpileImportInput(), 'runs', 0);

        self::assertCount(1, $violations);
        self::assertSame('runs', $violations[0]->getPropertyPath());
    }

    public function testTypeIdOfZeroIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new StockpileImportInput(), 'typeId', 0);

        self::assertCount(1, $violations);
        self::assertSame('typeId', $violations[0]->getPropertyPath());
    }
}
