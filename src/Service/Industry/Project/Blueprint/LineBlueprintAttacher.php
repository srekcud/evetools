<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Blueprint;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineBlueprint;
use App\Entity\User;
use App\Exception\InvalidLineBlueprintException;

final readonly class LineBlueprintAttacher
{
    public function __construct(private LineBlueprintPositions $positions)
    {
    }

    public function attach(IndustryProjectBomLine $line, User $attacher, int $itemId, \DateTimeImmutable $attachedAt): IndustryProjectLineBlueprint
    {
        if (!$line->isManufacturingOrReactionJob()) {
            throw new InvalidLineBlueprintException(InvalidLineBlueprintException::LINE_NOT_BLUEPRINT_STEP);
        }

        $blueprint = $this->positions->visibleByItemId($attacher)[$itemId] ?? null;
        if (null === $blueprint) {
            throw new InvalidLineBlueprintException(InvalidLineBlueprintException::BLUEPRINT_NOT_VISIBLE);
        }

        if ($blueprint->getTypeId() !== $this->positions->blueprintTypeOf($line)) {
            throw new InvalidLineBlueprintException(InvalidLineBlueprintException::BLUEPRINT_DOES_NOT_FIT_LINE);
        }

        $attachment = new IndustryProjectLineBlueprint($line, $itemId, $attacher, $attachedAt);
        $line->getBlueprints()->add($attachment);

        return $attachment;
    }
}
