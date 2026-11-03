<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Entity\ReprocessingProfile;
use App\Enum\ReprocessingFailure;
use Psr\Clock\ClockInterface;

final readonly class ReprocessingProfileRecalculator
{
    public function __construct(
        private ReprocessingSkillLevelReader $skillLevelReader,
        private ReprocessingYieldCalculator $yieldCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function recalculate(ReprocessingProfile $profile): void
    {
        $levels = $this->skillLevelReader->read($profile->getCharacter());
        $profile->setComputedAt($this->clock->now());

        if ($levels instanceof ReprocessingFailure) {
            $profile
                ->setReprocessingLevel(null)
                ->setReprocessingEfficiencyLevel(null)
                ->setOreProcessingLevel(null)
                ->setReprocessYield(null)
                ->setFailure($levels);

            return;
        }

        $oreProcessingLevel = $levels->oreProcessing($profile->getOreProcessingSkillTypeId());

        $profile
            ->setReprocessingLevel($levels->reprocessing)
            ->setReprocessingEfficiencyLevel($levels->reprocessingEfficiency)
            ->setOreProcessingLevel($oreProcessingLevel)
            ->setReprocessYield($this->yieldCalculator->calculate(
                $levels->reprocessing,
                $levels->reprocessingEfficiency,
                $oreProcessingLevel,
                $profile->getLocation(),
                $profile->getRigTier(),
                $profile->getSecurity(),
                $profile->getImplantPercent(),
            ))
            ->setFailure(null);
    }
}
