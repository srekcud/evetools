<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectSale;
use App\Entity\IndustryProject;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryProjectRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

trait ResolvesSaleOfProject
{
    /**
     * @param array<string, string> $uriVariables
     */
    private function projectOf(IndustryProjectRepository $projectRepository, array $uriVariables): IndustryProject
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
    private function saleOf(IndustryProjectSaleRepository $saleRepository, IndustryProject $project, array $uriVariables): IndustryProjectSale
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
