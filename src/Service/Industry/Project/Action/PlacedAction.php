<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Enum\StructureAccessStatus;

final readonly class PlacedAction
{
    private const string NO_LINE = '-';

    /**
     * @param list<array{characterName: ?string, quantity: int}> $reservedByOthers
     */
    public function __construct(
        public string $projectId,
        public ?string $projectName,
        public PlacedActionCode $code,
        public ?string $bomLineId,
        public ?int $typeId,
        public ?string $typeName,
        public ?int $quantity,
        public ActionPlace $place,
        public ?StructureAccessStatus $access = null,
        public array $reservedByOthers = [],
    ) {
    }

    public function id(): string
    {
        return sprintf('%s:%s:%s', $this->projectId, $this->code->value, $this->bomLineId ?? self::NO_LINE);
    }
}
