<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Input\Industry\CreateProjectInput;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Service\Industry\EstimatedSellPriceEstimator;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\ProductionCostService;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateProjectInput, ProjectResource>
 */
class CreateProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectFactory $projectFactory,
        private readonly IndustryResourceMapper $mapper,
        private readonly ProductionCostService $productionCostService,
        private readonly EstimatedSellPriceEstimator $sellPriceEstimator,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateProjectInput);

        try {
            $project = $this->projectFactory->createProject(
                $user,
                $data->typeId,
                $data->runs,
                $data->meLevel,
                $data->maxJobDurationDays,
                $data->teLevel,
                $data->name,
                $data->excludedTypeIds,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // Calculate estimated costs at creation time (snapshot)
        try {
            $jobInstallResult = $this->productionCostService->estimateJobInstallCosts($project);
            $project->setEstimatedJobCost($jobInstallResult['total']);
        } catch (\Throwable $e) {
            $this->logEstimationFailure('job_install_cost', $project, $e);
        }

        try {
            $materialResult = $this->productionCostService->estimateMaterialCost($project);
            $project->setEstimatedMaterialCost($materialResult['total']);
        } catch (\Throwable $e) {
            $this->logEstimationFailure('material_cost', $project, $e);
        }

        try {
            $estimatedSellPrice = $this->sellPriceEstimator->estimateFor($project);
            if ($estimatedSellPrice !== null) {
                $project->recordEstimatedSellPrice($estimatedSellPrice->amount, $estimatedSellPrice->source, $estimatedSellPrice->taxAmount);
            }
        } catch (\Throwable $e) {
            $this->logEstimationFailure('sell_price', $project, $e);
        }

        $this->entityManager->flush();

        return $this->mapper->projectToResource($project);
    }

    private function logEstimationFailure(string $estimation, IndustryProject $project, \Throwable $exception): void
    {
        $this->logger->warning('Industry project estimation failed', [
            'estimation' => $estimation,
            'projectId' => $project->getId()?->toRfc4122(),
            'reason' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
