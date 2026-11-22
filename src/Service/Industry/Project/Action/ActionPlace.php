<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

final readonly class ActionPlace
{
    public const string KIND_STATION = 'station';
    public const string KIND_ANYWHERE = 'anywhere';
    public const string KIND_UNKNOWN = 'unknown';

    public const string UNKNOWN_MARKET_NOT_CHOSEN = 'market_not_chosen';
    public const string UNKNOWN_MARKET_STRUCTURE_UNSET = 'market_structure_unset';
    public const string UNKNOWN_STATION_UNRESOLVED = 'station_unresolved';

    private function __construct(
        public string $kind,
        public ?int $locationId,
        public ?string $name,
        public ?int $solarSystemId,
        public ?string $solarSystemName,
        public ?string $unknownReason,
    ) {
    }

    public static function station(int $locationId, ?string $name, ?int $solarSystemId, ?string $solarSystemName): self
    {
        return new self(self::KIND_STATION, $locationId, $name, $solarSystemId, $solarSystemName, null);
    }

    public static function anywhere(): self
    {
        return new self(self::KIND_ANYWHERE, null, null, null, null, null);
    }

    public static function unknown(string $unknownReason): self
    {
        return new self(self::KIND_UNKNOWN, null, null, null, null, $unknownReason);
    }

    /**
     * @return array{kind: string, locationId: ?int, name: ?string, solarSystemId: ?int, solarSystemName: ?string, unknownReason: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'locationId' => $this->locationId,
            'name' => $this->name,
            'solarSystemId' => $this->solarSystemId,
            'solarSystemName' => $this->solarSystemName,
            'unknownReason' => $this->unknownReason,
        ];
    }
}
