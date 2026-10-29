<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class CreateProjectInput
{
    public const int MAXIMUM_PROJECT_RUNS = 100000;
    public const float MINIMUM_MAX_JOB_DURATION_DAYS = 0.5;
    private const int MAX_EXCLUDED_COMPONENTS = 500;

    #[Assert\NotNull]
    #[Assert\Positive]
    public int $typeId;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: self::MAXIMUM_PROJECT_RUNS)]
    public int $runs = 1;

    #[Assert\Range(min: 0, max: 10)]
    public int $meLevel = 0;

    #[TimeEfficiencyLevel]
    public int $teLevel = 0;

    #[Assert\GreaterThanOrEqual(self::MINIMUM_MAX_JOB_DURATION_DAYS)]
    public float $maxJobDurationDays = 2.0;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    /** @var list<int> */
    #[Assert\Count(max: self::MAX_EXCLUDED_COMPONENTS)]
    #[Assert\All([new Assert\Type('int'), new Assert\Positive()])]
    public array $excludedTypeIds = [];
}
