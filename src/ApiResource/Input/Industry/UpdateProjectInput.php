<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use App\Enum\ProjectStatus;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateProjectInput
{
    #[Assert\Range(min: 1, max: CreateProjectInput::MAXIMUM_PROJECT_RUNS)]
    public ?int $runs = null;

    #[Assert\Range(min: 0, max: 10)]
    public ?int $meLevel = null;

    #[TimeEfficiencyLevel]
    public ?int $teLevel = null;

    #[Assert\GreaterThanOrEqual(CreateProjectInput::MINIMUM_MAX_JOB_DURATION_DAYS)]
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

    #[Assert\NotBlank(allowNull: true)]
    #[Assert\DateTime(format: \DateTimeInterface::ATOM)]
    public ?string $jobsStartDate = null;

    /** @var array<array{typeId: int, typeName: string, quantity: int}>|null */
    #[Assert\All([
        new Assert\Collection(
            fields: ['typeName' => [new Assert\NotBlank(), new Assert\Type('string')]],
            allowExtraFields: true,
        ),
    ])]
    public ?array $inventionMaterials = null;
}
