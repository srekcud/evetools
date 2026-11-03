<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\MiningOperation;

use App\ApiResource\Input\MiningOperation\JoinMiningOperationInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(JoinMiningOperationInput::class)]
#[Group('issue-262')]
final class JoinMiningOperationInputValidationTest extends TestCase
{
    use ViolatedFieldsOf;

    private const string BRANN_HOLT_ID = '9c1b4e7a-2d58-4f36-b0a9-3e6d8f2c1a47';
    private const string BRANN_II_ID = '2f7a9d3c-6e14-4b82-a5c0-8d1e4b7f9a36';

    public function testJoiningWithBrannHoltAndBrannIIIsValid(): void
    {
        self::assertSame([], $this->violatedFieldsOf($this->joining([self::BRANN_HOLT_ID, self::BRANN_II_ID])));
    }

    public function testJoiningWithoutTickedCharacterIsRejected(): void
    {
        self::assertSame(['characterIds'], $this->violatedFieldsOf($this->joining([])));
    }

    public function testJoiningWithTheSameCharacterTwiceIsRejected(): void
    {
        self::assertSame(['characterIds'], $this->violatedFieldsOf($this->joining([self::BRANN_II_ID, self::BRANN_II_ID])));
    }

    public function testJoiningWithACharacterIdThatIsNotAUuidIsRejected(): void
    {
        self::assertSame(['characterIds'], $this->violatedFieldsOf($this->joining(['abc'])));
    }

    private function joining(array $characterIds): JoinMiningOperationInput
    {
        $input = new JoinMiningOperationInput();
        $input->characterIds = $characterIds;

        return $input;
    }
}
