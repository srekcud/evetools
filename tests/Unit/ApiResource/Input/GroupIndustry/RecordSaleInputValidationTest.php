<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\GroupIndustry;

use App\ApiResource\Input\GroupIndustry\RecordSaleInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(RecordSaleInput::class)]
#[Group('issue-164')]
final class RecordSaleInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const int MAXIMUM_QUANTITY = 2147483647;
    private const int MAXIMUM_TEXT_LENGTH = 255;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testIshtarSaleWithAnAtomSaleDateIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf(['soldAt' => '2026-10-25T14:30:00+00:00']));
    }

    #[DataProvider('saleDatesOutsideTheAtomFormat')]
    public function testSaleDateThatIsNotAValidAtomDateIsRejectedOnTheSaleDate(string $soldAt): void
    {
        self::assertSame(['soldAt'], $this->violatedPathsOf(['soldAt' => $soldAt]));
    }

    public static function saleDatesOutsideTheAtomFormat(): iterable
    {
        yield 'S2 not a date' => ['not-a-date'];
        yield 'S3 thirtieth of February' => ['2026-02-30T10:00:00+00:00'];
        yield 'S4 relative date' => ['tomorrow'];
        yield 'S6 date without time' => ['2026-10-25'];
        yield 'milliseconds' => ['2026-10-25T14:30:00.000+00:00'];
        yield 'without time zone' => ['2026-10-25T14:30:00'];
    }

    #[DataProvider('atomSaleDates')]
    public function testAtomSaleDateWithATimeZoneIsAccepted(string $soldAt): void
    {
        self::assertSame([], $this->violatedPathsOf(['soldAt' => $soldAt]));
    }

    public static function atomSaleDates(): iterable
    {
        yield 'S5 UTC written Z' => ['2026-10-25T14:30:00Z'];
        yield 'offset of two hours' => ['2026-10-25T16:30:00+02:00'];
    }

    public function testSaleWithoutSaleDateIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf([], without: ['soldAt']));
    }

    #[DataProvider('quantitiesOutOfRange')]
    public function testQuantityOutOfRangeIsRejectedOnTheQuantity(int $quantity): void
    {
        self::assertSame(['quantity'], $this->violatedPathsOf(['quantity' => $quantity]));
    }

    public static function quantitiesOutOfRange(): iterable
    {
        yield 'S8 zero' => [0];
        yield 'S9 negative' => [-2];
        yield 'S11 above the integer column' => [self::MAXIMUM_QUANTITY + 1];
    }

    #[DataProvider('quantitiesAtTheBounds')]
    public function testQuantityAtTheBoundsIsAccepted(int $quantity): void
    {
        self::assertSame([], $this->violatedPathsOf(['quantity' => $quantity]));
    }

    public static function quantitiesAtTheBounds(): iterable
    {
        yield 'one unit' => [1];
        yield 'largest integer column value' => [self::MAXIMUM_QUANTITY];
    }

    #[DataProvider('requiredFields')]
    public function testSaleWithoutARequiredFieldIsRejectedOnThatField(string $missingField): void
    {
        self::assertSame([$missingField], $this->violatedPathsOf([], without: [$missingField]));
    }

    public static function requiredFields(): iterable
    {
        yield 'typeId' => ['typeId'];
        yield 'typeName' => ['typeName'];
        yield 'S10 quantity' => ['quantity'];
        yield 'unitPrice' => ['unitPrice'];
    }

    public function testNegativeUnitPriceIsRejectedOnTheUnitPrice(): void
    {
        self::assertSame(['unitPrice'], $this->violatedPathsOf(['unitPrice' => -1.0]));
    }

    public function testFreeUnitPriceIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf(['unitPrice' => 0.0]));
    }

    public function testNegativeTypeIdIsRejectedOnTheTypeId(): void
    {
        self::assertSame(['typeId'], $this->violatedPathsOf(['typeId' => -1]));
    }

    public function testEmptyTypeNameIsRejectedOnTheTypeName(): void
    {
        self::assertSame(['typeName'], $this->violatedPathsOf(['typeName' => '']));
    }

    public function testTypeNameOfTheMaximumLengthIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf(['typeName' => str_repeat('I', self::MAXIMUM_TEXT_LENGTH)]));
    }

    public function testTypeNameLongerThanTheMaximumIsRejectedOnTheTypeName(): void
    {
        self::assertSame(['typeName'], $this->violatedPathsOf(['typeName' => str_repeat('I', self::MAXIMUM_TEXT_LENGTH + 1)]));
    }

    public function testVenueOfTheMaximumLengthIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf(['venue' => str_repeat('J', self::MAXIMUM_TEXT_LENGTH)]));
    }

    public function testVenueLongerThanTheMaximumIsRejectedOnTheVenue(): void
    {
        self::assertSame(['venue'], $this->violatedPathsOf(['venue' => str_repeat('J', self::MAXIMUM_TEXT_LENGTH + 1)]));
    }

    public function testSaleWithoutVenueIsAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf(['venue' => null]));
    }

    #[Group('issue-241')]
    #[DataProvider('sellerCharacterIdsThatAreNotPositive')]
    public function testSellerCharacterIdThatIsNotPositiveIsRejectedOnTheSellerCharacterId(int $sellerCharacterId): void
    {
        self::assertSame(['sellerCharacterId'], $this->violatedPathsOf(['sellerCharacterId' => $sellerCharacterId]));
    }

    public static function sellerCharacterIdsThatAreNotPositive(): iterable
    {
        yield 'A4 zero' => [0];
        yield 'A4 negative' => [-5];
    }

    #[Group('issue-241')]
    #[DataProvider('acceptedSellerCharacterIds')]
    public function testSaleWithAPositiveSellerCharacterIdOrWithoutSellerIsAccepted(array $seller): void
    {
        self::assertSame([], $this->violatedPathsOf($seller));
    }

    public static function acceptedSellerCharacterIds(): iterable
    {
        yield 'A1 alt of the owner' => [['sellerCharacterId' => 2120000002]];
        yield 'A5 positive but unknown to the application' => [['sellerCharacterId' => 99999999]];
        yield 'smallest positive identifier' => [['sellerCharacterId' => 1]];
        yield 'A2 null seller' => [['sellerCharacterId' => null]];
        yield 'A2 seller left out' => [[]];
    }

    private function violatedPathsOf(array $changes, array $without = []): array
    {
        $fields = array_diff_key(
            array_merge(['typeId' => 0, 'typeName' => 'Ishtar', 'quantity' => 5, 'unitPrice' => 1200000.0, 'venue' => 'Jita IV - Moon 4'], $changes),
            array_flip($without),
        );

        $input = new RecordSaleInput();
        foreach ($fields as $field => $value) {
            $input->{$field} = $value;
        }

        return $this->uniquePropertyPathsOf($this->validator->validate($input));
    }
}
