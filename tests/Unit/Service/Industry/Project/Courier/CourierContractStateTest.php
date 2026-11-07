<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Courier;

use App\Service\Industry\Project\Courier\CourierContractState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CourierContractState::class)]
#[Group('issue-351')]
final class CourierContractStateTest extends TestCase
{
    #[DataProvider('esiStatuses')]
    public function testEsiStatusIsMappedToTheTransportState(string $esiStatus, CourierContractState $expected): void
    {
        self::assertSame($expected, CourierContractState::fromEsiStatus($esiStatus));
    }

    public static function esiStatuses(): iterable
    {
        yield 'outstanding is waiting' => ['outstanding', CourierContractState::Waiting];
        yield 'finished by the contractor is delivered' => ['finished_contractor', CourierContractState::Delivered];
        yield 'cancelled is failed' => ['cancelled', CourierContractState::Failed];
        yield 'an unlisted status is unknown, never delivered' => ['archived', CourierContractState::Unknown];
    }
}
