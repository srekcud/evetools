<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StructureAccess;

use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Service\Industry\Project\StructureAccess\MemberStructureAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberStructureAccess::class)]
#[Group('issue-1299')]
final class MemberStructureAccessTest extends TestCase
{
    use BuildsStructureAccess;

    /**
     * @param list<?StructureAccessStatus> $characterStatuses
     */
    #[DataProvider('characterStatusesWithTheMemberStatus')]
    public function testMemberStatusIsGrantedIfOneCharacterIsGrantedAndDeniedOnlyIfAllAre(array $characterStatuses, StructureAccessStatus $expected): void
    {
        $characters = [];
        $rows = [];
        foreach ($characterStatuses as $characterStatus) {
            $character = $this->characterOf();
            $characters[] = $character;
            if (null !== $characterStatus) {
                $rows[] = $this->accessRowOf($character, self::KEEPSTAR_STRUCTURE_ID, $characterStatus, StructureAccessSource::Esi, '2026-11-21T10:00:00+00:00');
            }
        }

        $status = (new MemberStructureAccess($this->readerHolding($rows)))->of($this->pilotOf(...$characters), self::KEEPSTAR_STRUCTURE_ID);

        self::assertSame($expected, $status);
    }

    public static function characterStatusesWithTheMemberStatus(): iterable
    {
        yield 'one character without proof' => [[null], StructureAccessStatus::Unknown];
        yield 'one denied character' => [[StructureAccessStatus::Denied], StructureAccessStatus::Denied];
        yield 'every character denied' => [[StructureAccessStatus::Denied, StructureAccessStatus::Denied], StructureAccessStatus::Denied];
        yield 'denied and unknown' => [[StructureAccessStatus::Denied, null], StructureAccessStatus::Unknown];
        yield 'denied and granted' => [[StructureAccessStatus::Denied, StructureAccessStatus::Granted], StructureAccessStatus::Granted];
        yield 'unknown and granted' => [[null, StructureAccessStatus::Granted], StructureAccessStatus::Granted];
        yield 'no character at all' => [[], StructureAccessStatus::Unknown];
    }

    public function testNpcStationIsGrantedEvenToAMemberWithoutCharacter(): void
    {
        $status = (new MemberStructureAccess($this->readerHolding()))->of($this->pilotOf(), self::JITA_FORTIZAR_STATION_ID);

        self::assertSame(StructureAccessStatus::Granted, $status);
    }
}
