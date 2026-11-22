<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Enum\SourcingMarket;
use App\Industry\Application\SourcingSide;
use App\Repository\IndustryProjectBomLineRepository;

final readonly class ProjectSourcingChoices
{
    public function __construct(private IndustryProjectBomLineRepository $bomLineRepository)
    {
    }

    /**
     * @return array<int, bool>
     */
    public function buysOf(IndustryProject $project, string $boughtTypeIdsQuery, string $builtTypeIdsQuery): array
    {
        $buys = [];
        foreach ($project->getBlacklistTypeIds() as $typeId) {
            $buys[$typeId] = true;
        }
        foreach (self::typeIdsOf($boughtTypeIdsQuery) as $typeId) {
            $buys[$typeId] = true;
        }
        foreach (self::typeIdsOf($builtTypeIdsQuery) as $typeId) {
            $buys[$typeId] = false;
        }

        return $buys;
    }

    /**
     * @return array<int, SourcingSide>
     */
    public function marketsOf(IndustryProject $project): array
    {
        $markets = [];
        foreach ($this->bomLineRepository->findBy(['project' => $project]) as $line) {
            $market = $line->getSourcingMarket();
            if ($line->isJob() || null === $market) {
                continue;
            }
            $markets[$line->getTypeId()] = match ($market) {
                SourcingMarket::Jita => SourcingSide::Jita,
                SourcingMarket::Structure => SourcingSide::Structure,
            };
        }

        return $markets;
    }

    /**
     * @return list<int>
     */
    private static function typeIdsOf(string $commaSeparated): array
    {
        $typeIds = [];
        foreach (explode(',', $commaSeparated) as $candidate) {
            $trimmed = trim($candidate);
            if ('' !== $trimmed && ctype_digit($trimmed)) {
                $typeIds[] = (int) $trimmed;
            }
        }

        return $typeIds;
    }
}
