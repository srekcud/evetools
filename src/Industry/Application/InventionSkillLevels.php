<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Decryptor;

final readonly class InventionSkillLevels
{
    private const int NO_SKILL = 0;

    public function __construct(
        public ?int $firstScience,
        public ?int $secondScience,
        public ?int $encryption,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NO_SKILL, self::NO_SKILL, self::NO_SKILL);
    }

    public function isKnown(): bool
    {
        return null !== $this->firstScience && null !== $this->secondScience && null !== $this->encryption;
    }

    public function settingsWith(?Decryptor $decryptor): InventionSettings
    {
        return new InventionSettings($this->firstScience, $this->secondScience, $this->encryption, $decryptor);
    }
}
