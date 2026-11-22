<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StationMigration;

use App\Exception\InvalidStationMigrationLogException;
use App\Service\Industry\Project\StationMigration\StationMigrationChange;
use App\Service\Industry\Project\StationMigration\StationMigrationChangeKind;
use App\Service\Industry\Project\StationMigration\StationMigrationLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(StationMigrationLog::class)]
#[CoversClass(InvalidStationMigrationLogException::class)]
#[Group('issue-1320')]
final class StationMigrationLogTest extends TestCase
{
    private const string FIRST_PROJECT_ID = '0191b000-0000-7000-8000-000000000001';
    private const string SECOND_PROJECT_ID = '0191b000-0000-7000-8000-000000000002';
    private const string STRUCTURE_ID = '0191b000-0000-7000-8000-0000000000a1';
    private const string CONTAINER_ID = '0191b000-0000-7000-8000-0000000000c1';
    private const string SECOND_CONTAINER_ID = '0191b000-0000-7000-8000-0000000000c2';
    private const string FORGE_STATION_LOCATION_ID = '60003760';
    private const string AMARR_STATION_LOCATION_ID = '60008494';

    private string $directory;
    private string $path;
    private StationMigrationLog $log;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/station-migration-log-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->path = $this->directory . '/changes.json';
        $this->log = new StationMigrationLog();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testWrittenChangesAreReadBackInTheSameOrder(): void
    {
        $changes = [
            new StationMigrationChange(self::FIRST_PROJECT_ID, StationMigrationChangeKind::WorkStation, 'components', self::STRUCTURE_ID),
            new StationMigrationChange(self::FIRST_PROJECT_ID, StationMigrationChangeKind::ContainerLocation, self::CONTAINER_ID, self::FORGE_STATION_LOCATION_ID),
            new StationMigrationChange(self::SECOND_PROJECT_ID, StationMigrationChangeKind::ContainerLocation, self::SECOND_CONTAINER_ID, self::AMARR_STATION_LOCATION_ID),
        ];

        $this->log->write($this->path, $changes, new \DateTimeImmutable('2026-11-22 10:30:00 UTC'));

        self::assertEquals($changes, $this->log->read($this->path));
    }

    public function testTheFileHoldsTheApplicationDateAndOneEntryPerChange(): void
    {
        $appliedAt = new \DateTimeImmutable('2026-11-22 10:30:00 UTC');

        $this->log->write($this->path, [
            new StationMigrationChange(self::FIRST_PROJECT_ID, StationMigrationChangeKind::WorkStation, 'reaction', self::STRUCTURE_ID),
            new StationMigrationChange(self::SECOND_PROJECT_ID, StationMigrationChangeKind::ContainerLocation, self::CONTAINER_ID, self::FORGE_STATION_LOCATION_ID),
        ], $appliedAt);

        self::assertFileExists($this->path);
        $decoded = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['appliedAt', 'changes'], array_keys($decoded));
        self::assertEquals($appliedAt, new \DateTimeImmutable($decoded['appliedAt']));
        self::assertSame(
            [
                ['projectId' => self::FIRST_PROJECT_ID, 'kind' => 'work_station', 'subject' => 'reaction', 'value' => self::STRUCTURE_ID],
                ['projectId' => self::SECOND_PROJECT_ID, 'kind' => 'container_location', 'subject' => self::CONTAINER_ID, 'value' => self::FORGE_STATION_LOCATION_ID],
            ],
            $decoded['changes'],
        );
    }

    public function testAnEmptyLogIsWrittenAndReadBackWithoutChange(): void
    {
        $this->log->write($this->path, [], new \DateTimeImmutable('2026-11-22 10:30:00 UTC'));

        self::assertFileExists($this->path);
        self::assertSame([], $this->log->read($this->path));
    }

    public function testWritingInAMissingDirectoryFails(): void
    {
        $this->expectException(\Throwable::class);

        $this->log->write($this->directory . '/missing/changes.json', [], new \DateTimeImmutable('2026-11-22 10:30:00 UTC'));
    }

    public function testWritingInAMissingDirectoryCreatesNoFile(): void
    {
        try {
            $this->log->write($this->directory . '/missing/changes.json', [], new \DateTimeImmutable('2026-11-22 10:30:00 UTC'));
        } catch (\Throwable) {
        }

        self::assertFileDoesNotExist($this->directory . '/missing/changes.json');
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testReadingAMissingFileFails(): void
    {
        $this->expectException(InvalidStationMigrationLogException::class);

        $this->log->read($this->directory . '/absent.json');
    }

    public function testReadingInvalidJsonFails(): void
    {
        file_put_contents($this->path, '{"appliedAt": "2026-11-22T10:30:00+00:00", "changes": [');

        $this->expectException(InvalidStationMigrationLogException::class);

        $this->log->read($this->path);
    }

    public function testReadingAnEmptyFileFails(): void
    {
        file_put_contents($this->path, '');

        $this->expectException(InvalidStationMigrationLogException::class);

        $this->log->read($this->path);
    }

    public static function unexpectedShapes(): array
    {
        $base = [
            'projectId' => self::FIRST_PROJECT_ID,
            'kind' => 'work_station',
            'subject' => 'components',
            'value' => self::STRUCTURE_ID,
        ];
        $entry = static fn (array $overrides = [], array $without = []): array => array_replace(array_diff_key($base, array_flip($without)), $overrides);
        $wrap = static fn (mixed $changes): string => json_encode(['appliedAt' => '2026-11-22T10:30:00+00:00', 'changes' => $changes], JSON_THROW_ON_ERROR);

        return [
            'a scalar document' => ['"changes"'],
            'a list document' => ['[]'],
            'null document' => ['null'],
            'no changes key' => [json_encode(['appliedAt' => '2026-11-22T10:30:00+00:00'], JSON_THROW_ON_ERROR)],
            'changes is a string' => [$wrap('none')],
            'changes is null' => [$wrap(null)],
            'an entry that is a string' => [$wrap(['work_station'])],
            'an entry without projectId' => [$wrap([$entry([], ['projectId'])])],
            'an entry without kind' => [$wrap([$entry([], ['kind'])])],
            'an entry without subject' => [$wrap([$entry([], ['subject'])])],
            'an entry without value' => [$wrap([$entry([], ['value'])])],
            'an entry with a null projectId' => [$wrap([$entry(['projectId' => null])])],
            'an entry with a null value' => [$wrap([$entry(['value' => null])])],
            'an entry with an array subject' => [$wrap([$entry(['subject' => ['components']])])],
            'an entry with an array value' => [$wrap([$entry(['value' => [self::STRUCTURE_ID]])])],
            'a valid entry followed by a broken one' => [$wrap([$entry(), $entry([], ['value'])])],
        ];
    }

    #[DataProvider('unexpectedShapes')]
    public function testReadingAFileWithAnUnexpectedShapeFails(string $content): void
    {
        file_put_contents($this->path, $content);

        $this->expectException(InvalidStationMigrationLogException::class);

        $this->log->read($this->path);
    }

    public function testReadingAnEntryOfUnknownKindFails(): void
    {
        file_put_contents($this->path, json_encode([
            'appliedAt' => '2026-11-22T10:30:00+00:00',
            'changes' => [['projectId' => self::FIRST_PROJECT_ID, 'kind' => 'bom_line', 'subject' => 'components', 'value' => self::STRUCTURE_ID]],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidStationMigrationLogException::class);

        $this->log->read($this->path);
    }
}
