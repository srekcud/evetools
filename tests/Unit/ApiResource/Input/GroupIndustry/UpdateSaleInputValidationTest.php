<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\GroupIndustry;

use App\ApiResource\Input\GroupIndustry\UpdateSaleInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(UpdateSaleInput::class)]
#[Group('issue-164')]
final class UpdateSaleInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const int MAXIMUM_QUANTITY = 2147483647;
    private const int MAXIMUM_TEXT_LENGTH = 255;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testCorrectionWithoutAnyFieldIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf([]));
    }

    public function testCorrectionOfEveryFieldWithValidValuesIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf([
            'typeId' => 0,
            'typeName' => 'Sabre',
            'quantity' => 3,
            'unitPrice' => 950000.0,
            'venue' => 'Amarr VIII (Oris)',
            'soldAt' => '2026-10-24T09:00:00+00:00',
        ]));
    }

    public function testCorrectionThatLeavesTheVenueNullIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf(['venue' => null]));
    }

    #[DataProvider('correctionsOutOfRange')]
    public function testCorrectionWithAFieldOutOfRangeIsRejectedOnThatField(string $field, mixed $value): void
    {
        self::assertSame([$field], $this->violatedPathsOf([$field => $value]));
    }

    public static function correctionsOutOfRange(): iterable
    {
        yield 'C3 zero quantity' => ['quantity', 0];
        yield 'negative quantity' => ['quantity', -2];
        yield 'quantity above the integer column' => ['quantity', self::MAXIMUM_QUANTITY + 1];
        yield 'negative unit price' => ['unitPrice', -1.0];
        yield 'negative typeId' => ['typeId', -1];
        yield 'empty type name' => ['typeName', ''];
        yield 'type name longer than the maximum' => ['typeName', str_repeat('S', self::MAXIMUM_TEXT_LENGTH + 1)];
        yield 'venue longer than the maximum' => ['venue', str_repeat('A', self::MAXIMUM_TEXT_LENGTH + 1)];
        yield 'C4 sale date that is not a date' => ['soldAt', 'not-a-date'];
        yield 'thirtieth of February' => ['soldAt', '2026-02-30T10:00:00+00:00'];
        yield 'date without time' => ['soldAt', '2026-10-24'];
    }

    #[DataProvider('correctionsAtTheBounds')]
    public function testCorrectionWithAFieldAtItsBoundIsAccepted(string $field, mixed $value): void
    {
        self::assertSame([], $this->violatedPathsOf([$field => $value]));
    }

    public static function correctionsAtTheBounds(): iterable
    {
        yield 'one unit' => ['quantity', 1];
        yield 'largest integer column value' => ['quantity', self::MAXIMUM_QUANTITY];
        yield 'free unit price' => ['unitPrice', 0.0];
        yield 'type name of the maximum length' => ['typeName', str_repeat('S', self::MAXIMUM_TEXT_LENGTH)];
        yield 'venue of the maximum length' => ['venue', str_repeat('A', self::MAXIMUM_TEXT_LENGTH)];
        yield 'UTC sale date written Z' => ['soldAt', '2026-10-24T09:00:00Z'];
    }

    private function violatedPathsOf(array $fields): array
    {
        $input = new UpdateSaleInput();
        foreach ($fields as $field => $value) {
            $input->{$field} = $value;
        }

        return $this->uniquePropertyPathsOf($this->validator->validate($input));
    }
}
