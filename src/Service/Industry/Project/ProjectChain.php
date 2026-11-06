<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectChain
{
    /**
     * @param list<ProjectChainNode> $nodes
     */
    public function __construct(public array $nodes)
    {
    }
}
