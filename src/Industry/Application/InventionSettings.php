<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Decryptor;

/**
 * Skills and decryptor of the character who invents the T2 blueprint copies (spec R8).
 */
final readonly class InventionSettings
{
    public function __construct(
        public int $firstScienceSkillLevel,
        public int $secondScienceSkillLevel,
        public int $encryptionSkillLevel,
        public ?Decryptor $decryptor = null,
    ) {
    }
}
