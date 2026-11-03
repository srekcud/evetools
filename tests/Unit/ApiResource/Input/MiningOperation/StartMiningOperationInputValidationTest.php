<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\MiningOperation;

use App\ApiResource\Input\MiningOperation\StartMiningOperationInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartMiningOperationInput::class)]
#[Group('issue-263')]
final class StartMiningOperationInputValidationTest extends TestCase
{
    use ViolatedFieldsOf;

    public function testSolarSystemIdThatIsNotPositiveIsRejectedWhileS1OrNoSystemIsValid(): void
    {
        self::assertSame(
            ['S1' => [], 'no system' => [], 'zero' => ['solarSystemId'], 'negative' => ['solarSystemId']],
            [
                'S1' => $this->violatedFieldsOf($this->starting(30_003_504)),
                'no system' => $this->violatedFieldsOf($this->starting(null)),
                'zero' => $this->violatedFieldsOf($this->starting(0)),
                'negative' => $this->violatedFieldsOf($this->starting(-30_003_504)),
            ],
        );
    }

    private function starting(?int $solarSystemId): StartMiningOperationInput
    {
        $input = new StartMiningOperationInput();
        $input->solarSystemId = $solarSystemId;

        return $input;
    }
}
