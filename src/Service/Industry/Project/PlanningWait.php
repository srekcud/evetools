<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningWait
{
    public const string WAITING_JOB = 'waiting_job';
    public const string WAITING_MATERIALS = 'waiting_materials';
    public const string UNKNOWN = 'unknown';

    /**
     * @param list<string> $names type names of the awaited jobs or materials
     */
    public function __construct(public string $kind, public array $names, public int $lateSeconds)
    {
    }
}
