<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * A rig bonus as read from the SDE dogma attributes, given as a positive reduction percent (spec R4, D10).
 */
final readonly class RigBonus
{
    private const int PERCENT = 100;

    public function __construct(public float $reductionPercent, public RigSecurityModifiers $securityModifiers)
    {
        if (!is_finite($reductionPercent) || $reductionPercent < 0.0) {
            throw new \InvalidArgumentException(\sprintf('A rig reduction percent must be finite and not negative, got %F.', $reductionPercent));
        }
    }

    /**
     * mod_rig = 1 − (bonus % × security modifier) / 100.
     */
    public function multiplierIn(SecurityClass $security): Multiplier
    {
        $securityModifier = $this->securityModifiers->in($security);
        if (null === $securityModifier) {
            throw new \DomainException(\sprintf('This rig cannot be used in %s: the SDE gives it no security modifier there.', $security->name));
        }

        return new Multiplier(1 - $this->reductionPercent * $securityModifier / self::PERCENT);
    }
}
