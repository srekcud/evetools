<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Blueprint;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineBlueprint;
use App\Entity\OwnedBlueprint;

final readonly class LineBlueprintReading
{
    /**
     * @param array{typeId: int, typeName: string, itemId: int, quantity: int, from: array{locationId: int, name: string, solarSystemName: ?string}, to: array{locationId: int, name: ?string, solarSystemId: ?int, solarSystemName: ?string}}|null $transferToMake
     */
    public function __construct(
        public ?IndustryProjectLineBlueprint $attachment,
        public IndustryProjectBomLine $line,
        public int $itemId,
        public ?OwnedBlueprint $blueprint,
        public ?int $blueprintTypeId,
        public ?string $blueprintTypeName,
        public LineBlueprintPosition $position,
        public ?array $transferToMake,
    ) {
    }
}
