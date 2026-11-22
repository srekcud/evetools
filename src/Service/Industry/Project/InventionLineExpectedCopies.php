<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Industry\Application\Decryptors;
use App\Industry\Application\InventionCatalog;
use App\Industry\Domain\InventionAttempts;

final readonly class InventionLineExpectedCopies
{
    public function __construct(
        private InventionCatalog $inventions,
        private Decryptors $decryptors,
    ) {
    }

    public function of(IndustryProject $project, IndustryProjectBomLine $line): ?float
    {
        $attempts = $line->getRuns();
        if (IndustryProjectBomLine::ACTIVITY_INVENTION !== $line->getActivityType() || null === $attempts) {
            return null;
        }

        $baseProbability = $this->inventions->inventionOf($line->getTypeId())?->baseProbability;
        if (null === $baseProbability || $baseProbability <= 0.0) {
            return null;
        }

        $decryptorTypeId = $project->inventionDecryptorsByProductTypeId()[$line->getTypeId()] ?? null;
        $decryptor = null === $decryptorTypeId ? null : $this->decryptors->ofType($decryptorTypeId);
        if (null !== $decryptorTypeId && null === $decryptor) {
            return null;
        }

        return InventionAttempts::expectedBlueprintCopies($attempts, $baseProbability, $decryptor);
    }
}
