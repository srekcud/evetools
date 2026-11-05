<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Decryptor;
use App\Industry\Domain\InventionChance;

/**
 * Skills and decryptor of the character who invents the T2 blueprint copies (spec R8).
 */
final readonly class InventionSettings
{
    public function __construct(
        public ?int $firstScienceSkillLevel,
        public ?int $secondScienceSkillLevel,
        public ?int $encryptionSkillLevel,
        public ?Decryptor $decryptor = null,
    ) {
    }

    public function skillsAreKnown(): bool
    {
        return null !== $this->firstScienceSkillLevel && null !== $this->secondScienceSkillLevel && null !== $this->encryptionSkillLevel;
    }

    public function chanceOf(float $baseProbability): ?InventionChance
    {
        if (null === $this->firstScienceSkillLevel || null === $this->secondScienceSkillLevel || null === $this->encryptionSkillLevel) {
            return null;
        }

        return InventionChance::of($baseProbability, $this->firstScienceSkillLevel, $this->secondScienceSkillLevel, $this->encryptionSkillLevel, $this->decryptor);
    }
}
