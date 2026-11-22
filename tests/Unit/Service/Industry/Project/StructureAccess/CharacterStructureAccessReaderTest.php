<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StructureAccess;

use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterStructureAccessRepository;
use App\Service\Industry\Project\StructureAccess\CharacterStructureAccessReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterStructureAccessReader::class)]
#[Group('issue-1299')]
final class CharacterStructureAccessReaderTest extends TestCase
{
    use BuildsStructureAccess;

    #[DataProvider('npcStationIds')]
    public function testNpcStationIsGrantedWithoutReadingAnyProof(int $stationId): void
    {
        $accessRepository = $this->createMock(CharacterStructureAccessRepository::class);
        $accessRepository->expects(self::never())->method('findOneByCharacterAndStructure');
        $jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $jobRepository->expects(self::never())->method('latestEndDateAtStructure');

        $status = (new CharacterStructureAccessReader($accessRepository, $jobRepository))->statusOf($this->characterOf(), $stationId);

        self::assertSame(StructureAccessStatus::Granted, $status);
    }

    public static function npcStationIds(): iterable
    {
        yield 'Jita 4-4' => [60_003_760];
        yield 'last id below the structures' => [999_999_999_999];
    }

    #[DataProvider('proofsWithTheirStatus')]
    public function testMostRecentProofWins(?StructureAccessStatus $rowStatus, ?string $rowCheckedAt, ?string $jobEndDate, StructureAccessStatus $expected): void
    {
        $pilot = $this->characterOf();
        $rows = null === $rowStatus ? [] : [$this->accessRowOf($pilot, self::KEEPSTAR_STRUCTURE_ID, $rowStatus, StructureAccessSource::Esi, (string) $rowCheckedAt)];
        $jobs = null === $jobEndDate ? [] : [$this->proofKeyOf($pilot, self::KEEPSTAR_STRUCTURE_ID) => new \DateTimeImmutable($jobEndDate)];

        $status = $this->readerHolding($rows, $jobs)->statusOf($pilot, self::KEEPSTAR_STRUCTURE_ID);

        self::assertSame($expected, $status);
    }

    public static function proofsWithTheirStatus(): iterable
    {
        yield 'no proof' => [null, null, null, StructureAccessStatus::Unknown];
        yield 'a finished job alone' => [null, null, '2026-11-20T10:00:00+00:00', StructureAccessStatus::Granted];
        yield 'a denied row alone' => [StructureAccessStatus::Denied, '2026-11-20T12:00:00+00:00', null, StructureAccessStatus::Denied];
        yield 'a denied row newer than the job' => [StructureAccessStatus::Denied, '2026-11-20T12:00:00+00:00', '2026-11-20T10:00:00+00:00', StructureAccessStatus::Denied];
        yield 'a denied row older than the job' => [StructureAccessStatus::Denied, '2026-11-20T08:00:00+00:00', '2026-11-20T10:00:00+00:00', StructureAccessStatus::Granted];
        yield 'a granted row' => [StructureAccessStatus::Granted, '2026-11-20T08:00:00+00:00', null, StructureAccessStatus::Granted];
    }
}
