<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Input\Industry\UpdateProjectInput;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateProjectInput, ProjectResource>
 */
class UpdateProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectService $projectService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        if (!$data instanceof UpdateProjectInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        $regenerateSteps = false;

        if ($data->bpoCost !== null) {
            $project->setBpoCost($data->bpoCost);
        }
        if ($data->materialCost !== null) {
            $project->setMaterialCost($data->materialCost);
        }
        if ($data->transportCost !== null) {
            $project->setTransportCost($data->transportCost);
        }
        if ($data->taxAmount !== null) {
            $project->setTaxAmount($data->taxAmount);
        }
        if ($data->sellPrice !== null) {
            $project->setSellPrice($data->sellPrice);
        }
        if ($data->notes !== null) {
            $project->setNotes($data->notes);
        }
        if ($data->name !== null) {
            $name = trim($data->name);
            $project->setName($name !== '' ? $name : null);
        }
        if ($data->status !== null) {
            $project->setStatus($data->status);
            if ($data->status === 'completed' && $project->getCompletedAt() === null) {
                $project->setCompletedAt(new \DateTimeImmutable());
            }
        }
        if ($data->personalUse !== null) {
            $project->setPersonalUse($data->personalUse);
        }
        if ($data->jobsStartDate !== null) {
            $project->setJobsStartDate(new \DateTimeImmutable($data->jobsStartDate));
        }

        if ($data->runs !== null && $data->runs >= 1 && $data->runs !== $project->getRuns()) {
            $project->setRuns($data->runs);
            $regenerateSteps = true;
        }
        if ($data->maxJobDurationDays !== null && $data->maxJobDurationDays > 0 && $data->maxJobDurationDays !== $project->getMaxJobDurationDays()) {
            $project->setMaxJobDurationDays($data->maxJobDurationDays);
            $regenerateSteps = true;
        }
        if ($data->teLevel !== null && $data->teLevel >= 0 && $data->teLevel <= 20 && $data->teLevel !== $project->getTeLevel()) {
            $project->setTeLevel($data->teLevel);
            $regenerateSteps = true;
        }

        $this->entityManager->flush();

        if ($regenerateSteps) {
            $this->projectService->regenerateSteps($project);
        }

        $summary = $this->projectService->getProjectSummary($project);

        return $this->toResource($summary);
    }

    private function toResource(array $summary): ProjectResource
    {
        $resource = new ProjectResource();
        $resource->id = $summary['id'];
        $resource->productTypeId = $summary['productTypeId'];
        $resource->productTypeName = $summary['productTypeName'];
        $resource->name = $summary['name'] ?? null;
        $resource->displayName = $summary['displayName'] ?? $summary['productTypeName'];
        $resource->runs = $summary['runs'];
        $resource->meLevel = $summary['meLevel'];
        $resource->teLevel = $summary['teLevel'] ?? 0;
        $resource->maxJobDurationDays = $summary['maxJobDurationDays'];
        $resource->status = $summary['status'];
        $resource->profit = $summary['profit'] ?? null;
        $resource->profitPercent = $summary['profitPercent'] ?? null;
        $resource->bpoCost = $summary['bpoCost'] ?? null;
        $resource->materialCost = $summary['materialCost'] ?? null;
        $resource->transportCost = $summary['transportCost'] ?? null;
        $resource->taxAmount = $summary['taxAmount'] ?? null;
        $resource->sellPrice = $summary['sellPrice'] ?? null;
        $resource->jobsCost = $summary['jobsCost'] ?? null;
        $resource->totalCost = $summary['totalCost'] ?? null;
        $resource->notes = $summary['notes'] ?? null;
        $resource->personalUse = $summary['personalUse'] ?? false;
        $resource->jobsStartDate = $summary['jobsStartDate'] ?? null;
        $resource->completedAt = $summary['completedAt'] ?? null;
        $resource->createdAt = $summary['createdAt'];
        $resource->rootProducts = $summary['rootProducts'] ?? [];

        return $resource;
    }
}
