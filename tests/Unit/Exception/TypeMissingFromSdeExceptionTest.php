<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Exception\TypeMissingFromSdeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeMissingFromSdeException::class)]
final class TypeMissingFromSdeExceptionTest extends TestCase
{
    private const int ENRICHED_URANIUM = 44;
    private const int ROBOTICS = 9848;

    public function testMissingTypeIdsAreReportedOnceInAscendingOrder(): void
    {
        $exception = new TypeMissingFromSdeException([self::ROBOTICS, self::ENRICHED_URANIUM, self::ROBOTICS]);

        self::assertSame(
            [[self::ENRICHED_URANIUM, self::ROBOTICS], 'Types missing from the SDE: 44, 9848'],
            [$exception->getMissingTypeIds(), $exception->getMessage()],
        );
    }
}
