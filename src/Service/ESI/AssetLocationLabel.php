<?php

declare(strict_types=1);

namespace App\Service\ESI;

final class AssetLocationLabel
{
    private const string UNKNOWN_STRUCTURE = 'Unknown structure';
    private const string UNKNOWN_STATION = 'Unknown station';
    private const string ASSET_SAFETY = 'Asset Safety';
    private const string UNRESOLVED_STRUCTURE_PREFIX = 'Structure #';

    public static function unknownStructure(?string $solarSystemName): string
    {
        return self::withSolarSystem(self::UNKNOWN_STRUCTURE, $solarSystemName);
    }

    public static function unknownStation(?string $solarSystemName): string
    {
        return self::withSolarSystem(self::UNKNOWN_STATION, $solarSystemName);
    }

    public static function assetSafety(string $solarSystemName): string
    {
        return self::withSolarSystem(self::ASSET_SAFETY, $solarSystemName);
    }

    public static function isUnknownOrigin(string $locationName): bool
    {
        return str_starts_with($locationName, self::UNRESOLVED_STRUCTURE_PREFIX)
            || str_starts_with($locationName, self::UNKNOWN_STRUCTURE)
            || str_starts_with($locationName, self::UNKNOWN_STATION);
    }

    private static function withSolarSystem(string $label, ?string $solarSystemName): string
    {
        return $solarSystemName === null ? $label : "{$label} ({$solarSystemName})";
    }
}
