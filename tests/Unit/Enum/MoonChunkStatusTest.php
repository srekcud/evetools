<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\MoonChunkStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonChunkStatus::class)]
#[Group('issue-274')]
final class MoonChunkStatusTest extends TestCase
{
    private const string CHUNK_ARRIVAL = '2026-11-05 20:00:00';
    private const string NATURAL_DECAY = '2026-11-05 23:00:00';

    public function testAChunkIsExtractingBeforeItsArrivalReadyAfterItAndExplodedAtItsNaturalDecay(): void
    {
        self::assertSame(
            ['19:00' => 'extracting', '20:30' => 'ready', '23:00' => 'exploded'],
            $this->statusesAt(['19:00' => '2026-11-05 19:00:00', '20:30' => '2026-11-05 20:30:00', '23:00' => '2026-11-05 23:00:00']),
        );
    }

    public function testTheChunkArrivalIsAlreadyReadyAndTheNaturalDecayIsAlreadyExploded(): void
    {
        self::assertSame(
            ['19:59:59' => 'extracting', '20:00:00' => 'ready', '22:59:59' => 'ready', '23:00:00' => 'exploded'],
            $this->statusesAt([
                '19:59:59' => '2026-11-05 19:59:59',
                '20:00:00' => '2026-11-05 20:00:00',
                '22:59:59' => '2026-11-05 22:59:59',
                '23:00:00' => '2026-11-05 23:00:00',
            ]),
        );
    }

    public function testTheStatusComparesInstantsWhateverTheTimeZoneOfEachDate(): void
    {
        $chunkArrival = new \DateTimeImmutable(self::CHUNK_ARRIVAL, new \DateTimeZone('UTC'));
        $naturalDecay = new \DateTimeImmutable(self::NATURAL_DECAY, new \DateTimeZone('UTC'));

        self::assertSame(
            ['21:30 Paris' => 'ready', '00:00 Paris' => 'exploded', '20:59 Paris' => 'extracting'],
            [
                '21:30 Paris' => MoonChunkStatus::fromTimes(new \DateTimeImmutable('2026-11-05 21:30:00', new \DateTimeZone('Europe/Paris')), $chunkArrival, $naturalDecay)->value,
                '00:00 Paris' => MoonChunkStatus::fromTimes(new \DateTimeImmutable('2026-11-06 00:00:00', new \DateTimeZone('Europe/Paris')), $chunkArrival, $naturalDecay)->value,
                '20:59 Paris' => MoonChunkStatus::fromTimes(new \DateTimeImmutable('2026-11-05 20:59:00', new \DateTimeZone('Europe/Paris')), $chunkArrival, $naturalDecay)->value,
            ],
        );
    }

    private function statusesAt(array $instants): array
    {
        $utc = new \DateTimeZone('UTC');
        $chunkArrival = new \DateTimeImmutable(self::CHUNK_ARRIVAL, $utc);
        $naturalDecay = new \DateTimeImmutable(self::NATURAL_DECAY, $utc);

        return array_map(
            static fn (string $now): string => MoonChunkStatus::fromTimes(new \DateTimeImmutable($now, $utc), $chunkArrival, $naturalDecay)->value,
            $instants,
        );
    }
}
