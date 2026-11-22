<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Blueprint;

use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\AssetStation;

final readonly class LineBlueprintPosition
{
    public const string HERE = 'here';
    public const string ELSEWHERE = 'elsewhere';
    public const string UNKNOWN = 'unknown';

    public const string REASON_STEP_STATION_UNRESOLVED = 'step_station_unresolved';
    public const string REASON_BLUEPRINT_NOT_SYNCED = 'blueprint_not_synced';
    public const string REASON_ATTACHER_UNKNOWN = 'attacher_unknown';
    public const string REASON_ASSET_NOT_FOUND = 'asset_not_found';
    public const string REASON_STATION_UNRESOLVED = 'station_unresolved';

    public function __construct(
        public string $state,
        public ?string $unknownReason,
        public ActionPlace $stepPlace,
        public ?AssetStation $blueprintStation,
    ) {
    }

    public static function of(ActionPlace $stepPlace, ?AssetStation $blueprintStation, ?string $unknownReason): self
    {
        if (ActionPlace::KIND_STATION !== $stepPlace->kind) {
            return new self(self::UNKNOWN, self::REASON_STEP_STATION_UNRESOLVED, $stepPlace, $blueprintStation);
        }

        if (null === $blueprintStation) {
            return new self(self::UNKNOWN, $unknownReason ?? throw new \LogicException('A blueprint without station carries the reason why.'), $stepPlace, null);
        }

        return new self(
            $blueprintStation->locationId === $stepPlace->locationId ? self::HERE : self::ELSEWHERE,
            null,
            $stepPlace,
            $blueprintStation,
        );
    }
}
