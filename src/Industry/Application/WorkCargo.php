<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;

final readonly class WorkCargo
{
    /**
     * @param list<MissingData> $missingVolumes
     */
    private function __construct(
        public WorkTransportKind $kind,
        public ?WorkType $producedBy,
        public float $cubicMeters,
        private array $missingVolumes,
    ) {
    }

    /**
     * @param array<int, int> $unitsByTypeId
     */
    public static function of(WorkTransportKind $kind, ?WorkType $producedBy, array $unitsByTypeId, ItemVolumes $itemVolumes): self
    {
        $cubicMeters = 0.0;
        $missingVolumes = [];
        foreach ($unitsByTypeId as $typeId => $units) {
            $cubicMetersPerUnit = $itemVolumes->volumeOf($typeId);
            if (null === $cubicMetersPerUnit) {
                $missingVolumes[] = MissingData::itemVolume($typeId);

                continue;
            }
            $cubicMeters += $units * $cubicMetersPerUnit;
        }

        return new self($kind, $producedBy, $cubicMeters, $missingVolumes);
    }

    public function costAt(float $iskPerCubicMeter): Cost
    {
        return $this->costOf(Cost::known(new Isk($this->cubicMeters * $iskPerCubicMeter)));
    }

    public function costOf(Cost $costWhenVolumesAreKnown): Cost
    {
        return [] === $this->missingVolumes ? $costWhenVolumesAreKnown : Cost::unknown(...$this->missingVolumes);
    }
}
