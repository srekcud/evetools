<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\GroupIndustry;

use App\ApiResource\Input\GroupIndustry\RecordSaleFromWalletTransactionInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(RecordSaleFromWalletTransactionInput::class)]
#[Group('issue-241')]
final class RecordSaleFromWalletTransactionInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const string T7_TRANSACTION_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e51';
    private const int MAXIMUM_QUANTITY = 2147483647;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[DataProvider('acceptedRecordings')]
    public function testRecordingOfAnExistingTransactionIdentifierWithAQuantityWithinTheColumnOrWithoutQuantityIsAccepted(array $changes): void
    {
        self::assertSame([], $this->violatedPathsOf($changes));
    }

    public static function acceptedRecordings(): iterable
    {
        yield 'L1 whole transaction' => [[]];
        yield 'L3 ten units' => [['quantity' => 10]];
        yield 'L4 thirteen units, refused later against the transaction' => [['quantity' => 13]];
        yield 'smallest quantity' => [['quantity' => 1]];
        yield 'largest quantity of the column' => [['quantity' => self::MAXIMUM_QUANTITY]];
        yield 'quantity null' => [['quantity' => null]];
    }

    #[DataProvider('quantitiesOutOfRange')]
    public function testQuantityOutOfRangeIsRejectedOnTheQuantity(int $quantity): void
    {
        self::assertSame(['quantity'], $this->violatedPathsOf(['quantity' => $quantity]));
    }

    public static function quantitiesOutOfRange(): iterable
    {
        yield 'L4 zero' => [0];
        yield 'negative' => [-1];
        yield 'above the integer column' => [self::MAXIMUM_QUANTITY + 1];
    }

    #[DataProvider('transactionIdentifiersThatAreNotUuids')]
    public function testTransactionIdentifierThatIsNotAUuidIsRejectedOnTheTransactionIdentifier(array $changes, array $without): void
    {
        self::assertSame(['transactionId'], $this->violatedPathsOf($changes, $without));
    }

    public static function transactionIdentifiersThatAreNotUuids(): iterable
    {
        yield 'L11 not a UUID' => [['transactionId' => 'not-a-uuid'], []];
        yield 'ESI transaction number' => [['transactionId' => '6100000007'], []];
        yield 'blank' => [['transactionId' => ''], []];
        yield 'left out' => [[], ['transactionId']];
    }

    private function violatedPathsOf(array $changes, array $without = []): array
    {
        $fields = array_diff_key(
            array_merge(['transactionId' => self::T7_TRANSACTION_ID], $changes),
            array_flip($without),
        );

        $input = new RecordSaleFromWalletTransactionInput();
        foreach ($fields as $field => $value) {
            $input->{$field} = $value;
        }

        return $this->uniquePropertyPathsOf($this->validator->validate($input));
    }
}
