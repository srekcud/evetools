<?php

declare(strict_types=1);

namespace App\Dto\Assets;

final readonly class AssetScope
{
    /**
     * @param list<string> $rootFlags
     */
    private function __construct(
        public ?string $characterId,
        public ?int $corporationId,
        public array $rootFlags,
        public ?string $divisionName,
    ) {
    }

    public static function forCharacter(string $characterId): self
    {
        return new self($characterId, null, [], null);
    }

    /**
     * @param list<int> $visibleDivisionNumbers
     */
    public static function forCorporation(int $corporationId, array $visibleDivisionNumbers, ?string $divisionName = null): self
    {
        return new self(
            null,
            $corporationId,
            array_map(static fn (int $number): string => "CorpSAG{$number}", $visibleDivisionNumbers),
            $divisionName,
        );
    }

    public function isCorporation(): bool
    {
        return $this->corporationId !== null;
    }
}
