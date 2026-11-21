<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry\Project;

use App\ApiResource\Input\Industry\Project\RecordSalesFromWalletTransactionsInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(RecordSalesFromWalletTransactionsInput::class)]
#[Group('issue-1152')]
final class RecordSalesFromWalletTransactionsInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const string T1_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e01';
    private const string T7_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e07';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testTwoLinesOneWithAQuantityAreAccepted(): void
    {
        self::assertSame([], $this->violatedPathsOf([['transactionId' => self::T1_ID], ['transactionId' => self::T7_ID, 'quantity' => 5]]));
    }

    public function testTheLinesAreDenormalisedWithTheirTransactionIdentifierAndQuantity(): void
    {
        $input = new RecordSalesFromWalletTransactionsInput();
        $input->setTransactions([['transactionId' => self::T1_ID], ['transactionId' => self::T7_ID, 'quantity' => 5]]);

        self::assertSame(
            [[self::T1_ID, null], [self::T7_ID, 5]],
            array_map(static fn ($line): array => [$line->transactionId, $line->quantity], $input->transactions),
        );
    }

    public function testNoLineIsRejected(): void
    {
        self::assertSame(['transactions'], $this->violatedPathsOf([]));
    }

    public function testMoreThanTheMaximumNumberOfLinesIsRejected(): void
    {
        $lines = array_fill(0, RecordSalesFromWalletTransactionsInput::MAXIMUM_TRANSACTIONS_PER_BATCH + 1, ['transactionId' => self::T1_ID]);

        self::assertSame(['transactions'], $this->violatedPathsOf($lines));
    }

    public function testTheMaximumNumberOfLinesIsAccepted(): void
    {
        $lines = array_fill(0, RecordSalesFromWalletTransactionsInput::MAXIMUM_TRANSACTIONS_PER_BATCH, ['transactionId' => self::T1_ID]);

        self::assertSame([], $this->violatedPathsOf($lines));
    }

    public function testALineWithoutValidTransactionIdentifierOrWithAZeroQuantityIsRejectedOnThatLine(): void
    {
        self::assertSame(
            ['transactions[1].transactionId', 'transactions[2].quantity'],
            $this->violatedPathsOf([['transactionId' => self::T1_ID], ['transactionId' => 'not-a-uuid'], ['transactionId' => self::T7_ID, 'quantity' => 0]]),
        );
    }

    public function testALineThatIsNotAnObjectIsRejected(): void
    {
        self::assertNotSame([], $this->violatedPathsOf([self::T1_ID]));
    }

    private function violatedPathsOf(array $lines): array
    {
        $input = new RecordSalesFromWalletTransactionsInput();
        $input->setTransactions($lines);

        return $this->uniquePropertyPathsOf($this->validator->validate($input));
    }
}
