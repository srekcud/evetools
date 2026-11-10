<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningSite;

use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Repository\MiningSiteScanRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\MiningSite\MiningSiteHistorySummarizer;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MiningSiteResourceMapper::class)]
#[Group('issue-532')]
final class MiningSiteResourceMapperValuationSourceTest extends TestCase
{
    public function testAScanExposesTheValuationSourceItWasRecordedWith(): void
    {
        $source = ['market' => 'structure', 'side' => 'sell', 'form' => 'raw', 'structureName' => 'Refinery B-1'];

        $resource = $this->mapper()->scanToResource($this->scan()->setValuationSource($source));

        $this->assertSame($source, $resource->valuationSource);
    }

    public function testAScanRecordedBeforeTheProfileHasNoValuationSource(): void
    {
        $scan = $this->scan();

        $resource = $this->mapper()->scanToResource($scan);

        $this->assertNull($scan->getValuationSource());
        $this->assertNull($resource->valuationSource);
        $this->assertSame(30_000, $resource->totalUnits);
    }

    public function testEveryScanOfAThreadKeepsItsOwnValuationSource(): void
    {
        $buy = ['market' => 'jita', 'side' => 'buy', 'form' => 'compressed', 'structureName' => null];
        $scans = [$this->scan()->setValuationSource($buy), $this->scan(), $this->scan()->setValuationSource(['market' => 'jita', 'side' => 'sell', 'form' => 'compressed', 'structureName' => null])];

        $resources = $this->mapper()->scansToResources($scans);

        $this->assertSame(
            [$buy, null, ['market' => 'jita', 'side' => 'sell', 'form' => 'compressed', 'structureName' => null]],
            array_map(static fn ($resource): ?array => $resource->valuationSource, $resources),
        );
    }

    private function mapper(): MiningSiteResourceMapper
    {
        return new MiningSiteResourceMapper(
            $this->createStub(MiningSiteScanRepository::class),
            new MiningSiteHistorySummarizer(),
            $this->createStub(MapSolarSystemRepository::class),
        );
    }

    private function scan(): MiningSiteScan
    {
        $site = new MiningSite();
        (new \ReflectionProperty(MiningSite::class, 'id'))->setValue($site, Uuid::v7());
        $scan = (new MiningSiteScan())
            ->setSite($site)
            ->setScannedAt(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'))
            ->setTotalUnits(30_000);
        (new \ReflectionProperty(MiningSiteScan::class, 'id'))->setValue($scan, Uuid::v7());

        return $scan;
    }
}
