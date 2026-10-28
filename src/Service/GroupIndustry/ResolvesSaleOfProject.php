<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustrySale;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

trait ResolvesSaleOfProject
{
    /**
     * @param array<string, string> $uriVariables
     */
    private function projectOf(GroupIndustryProjectRepository $projectRepository, array $uriVariables): GroupIndustryProject
    {
        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null || !Uuid::isValid($projectId)) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        return $project;
    }

    /**
     * @param array<string, string> $uriVariables
     */
    private function saleOf(GroupIndustrySaleRepository $saleRepository, GroupIndustryProject $project, array $uriVariables): GroupIndustrySale
    {
        $saleId = $uriVariables['id'] ?? null;
        if ($saleId === null || !Uuid::isValid($saleId)) {
            throw new NotFoundHttpException('Sale not found');
        }

        $sale = $saleRepository->findOneBy(['id' => Uuid::fromString($saleId), 'project' => $project]);
        if ($sale === null) {
            throw new NotFoundHttpException('Sale not found');
        }

        return $sale;
    }
}
