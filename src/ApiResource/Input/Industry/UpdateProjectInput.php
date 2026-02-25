<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use App\Enum\ProjectStatus;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateProjectInput
{
    #[Assert\Positive]
    public ?int $runs = null;

    #[Assert\Range(min: 0, max: 10)]
    public ?int $meLevel = null;

    #[Assert\Range(min: 0, max: 20)]
    public ?int $teLevel = null;

    #[Assert\Positive]
    public ?float $maxJobDurationDays = null;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    public ?float $bpoCost = null;

    public ?float $materialCost = null;

    public ?float $transportCost = null;

    public ?float $taxAmount = null;

    public ?float $sellPrice = null;

    public ?string $notes = null;

    #[Assert\Choice(choices: ['active', 'completed', 'archived'])]
    public ?string $status = null;

    public ?bool $personalUse = null;

    public ?string $jobsStartDate = null;

    /** @var array<array{typeId: int, typeName: string, quantity: int}>|null */
    public ?array $inventionMaterials = null;
}
