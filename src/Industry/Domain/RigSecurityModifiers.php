<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Security scaling of a rig bonus, read from the SDE (hiSecModifier / lowSecModifier / nullSecModifier).
 * A null modifier means the rig cannot be used in that security class (e.g. reaction rigs in highsec).
 */
final readonly class RigSecurityModifiers
{
    public function __construct(public ?float $highSec, public ?float $lowSec, public ?float $nullSec)
    {
        foreach ([$highSec, $lowSec, $nullSec] as $modifier) {
            if (null !== $modifier && (!is_finite($modifier) || $modifier < 0.0)) {
                throw new \InvalidArgumentException(\sprintf('A rig security modifier must be finite and not negative, got %F.', $modifier));
            }
        }
    }

    public function in(SecurityClass $security): ?float
    {
        return match ($security) {
            SecurityClass::HighSec => $this->highSec,
            SecurityClass::LowSec => $this->lowSec,
            SecurityClass::NullSec => $this->nullSec,
        };
    }
}
