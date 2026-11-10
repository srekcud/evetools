<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Escalation;

use App\Entity\Escalation;
use Symfony\Component\Validator\Constraints as Assert;

class CreateEscalationInput
{
    public int $characterId;

    public string $type;

    public int $solarSystemId;

    public string $solarSystemName;

    public float $securityStatus;

    #[Assert\Range(min: 0, max: Escalation::MAXIMUM_PRICE)]
    public int $price;

    public ?string $notes = null;

    /** Timer in hours (max 72) */
    public float $timerHours = 72.0;

    /** Visibility: perso, corp, alliance, public */
    public string $visibility = 'perso';
}
