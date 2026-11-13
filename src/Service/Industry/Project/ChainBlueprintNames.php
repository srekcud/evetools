<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ChainBlueprintNames
{
    private const array PRODUCER_ACTIVITY_BY_JOB_ACTIVITY = [
        'manufacturing' => 'manufacturing',
        'copy' => 'manufacturing',
        'reaction' => 'reaction',
    ];

    public function __construct(private PlanningBlueprintNames $blueprintNames)
    {
    }

    /**
     * @param list<ProjectChainNode> $nodes
     *
     * @return array<string, string> node id => name of the blueprint or formula the job installs, absent when unknown
     */
    public function of(array $nodes): array
    {
        $products = [];
        foreach ($nodes as $node) {
            $producerActivity = null === $node->activity ? null : self::PRODUCER_ACTIVITY_BY_JOB_ACTIVITY[$node->activity] ?? null;
            if (null !== $producerActivity && null !== $node->typeId) {
                $products[$node->id] = ['typeId' => $node->typeId, 'activity' => $producerActivity];
            }
        }

        return $this->blueprintNames->byKey($products);
    }
}
