<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Entity\IndustryProject;

class ProjectProductTypes
{
    private const string FINAL_JOB_GROUP = 'final';

    /**
     * @return array<int, string> type name of each final product, by type id
     */
    public function of(IndustryProject $project): array
    {
        $productNames = [];
        foreach ($project->getBomItems() as $line) {
            if ($line->isJob() && $line->getJobGroup() === self::FINAL_JOB_GROUP) {
                $productNames[$line->getTypeId()] = $line->getTypeName();
            }
        }

        return $productNames;
    }
}
