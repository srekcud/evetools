<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\GroupIndustry;

use App\ApiResource\Input\GroupIndustry\SubmitContributionInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(SubmitContributionInput::class)]
#[Group('issue-257')]
final class SubmitContributionInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[DataProvider('quantities')]
    public function testQuantityOfAContributionIsBoundedByTheIntegerColumn(int $quantity, array $expectedPropertyPaths): void
    {
        self::assertSame($expectedPropertyPaths, $this->violatedPathsOf($this->materialContributionOf($quantity)));
    }

    public static function quantities(): iterable
    {
        yield 'one unit' => [1, []];
        yield 'the largest integer of the column' => [2147483647, []];
        yield 'one unit beyond the integer column' => [2147483648, ['quantity']];
        yield 'zero' => [0, ['quantity']];
        yield 'minus one' => [-1, ['quantity']];
    }

    private function materialContributionOf(int $quantity): SubmitContributionInput
    {
        $input = new SubmitContributionInput();
        $input->type = 'material';
        $input->bomItemId = '0192d4e8-7c3a-7b1e-9f4a-3c5d6e7f8a9b';
        $input->quantity = $quantity;

        return $input;
    }

    private function violatedPathsOf(SubmitContributionInput $input): array
    {
        return $this->uniquePropertyPathsOf($this->validator->validate($input));
    }
}
