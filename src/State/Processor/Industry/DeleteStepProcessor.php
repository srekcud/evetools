<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Exception\StepMaterialsMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\ProjectEstimatesRefresher;
use App\Service\Industry\SplitGroupQuantities;
use App\Service\Industry\StepMaterialTypeIdsReader;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class DeleteStepProcessor implements ProcessorInterface
{
    private const string MATERIALS_MISSING_FROM_SDE_MESSAGE = 'Cannot delete a step while the materials of a blueprint of the project are missing from the SDE';

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly IndustryStepCalculator $stepCalculator,
        private readonly StepMaterialTypeIdsReader $materialTypeIdsReader,
        private readonly ProjectEstimatesRefresher $estimatesRefresher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Project not found'));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $step = $this->stepRepository->find(UuidOrNotFound::of($uriVariables['stepId'], 'Step not found'));

        if ($step === null || $step->getProject() !== $project) {
            throw new NotFoundHttpException('Step not found');
        }

        try {
            $refusal = $project->refusalToDeleteStep($step, $this->materialTypeIdsReader->materialTypeIdsOf(...));
        } catch (StepMaterialsMissingFromSdeException $missingMaterials) {
            throw new BadRequestHttpException(self::MATERIALS_MISSING_FROM_SDE_MESSAGE, $missingMaterials);
        }

        if ($refusal !== null) {
            throw new BadRequestHttpException($refusal);
        }

        $otherJobs = $project->otherJobsOfGroupOf($step);

        if ($otherJobs !== []) {
            SplitGroupQuantities::passDeletedJobOnToGroup($step, $otherJobs);
        }

        $project->removeStep($step);
        $this->entityManager->remove($step);

        try {
            $this->stepCalculator->recalculateStepQuantities($project);
        } catch (BlueprintProductMissingFromSdeException $missingProduct) {
            throw new BadRequestHttpException($missingProduct->getMessage(), $missingProduct);
        }

        $this->estimatesRefresher->refresh($project);
        $this->entityManager->flush();
    }
}
