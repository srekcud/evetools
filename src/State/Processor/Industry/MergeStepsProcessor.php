<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Service\Industry\SplitGroupMaterialNeeds;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, ProjectStepResource>
 */
class MergeStepsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly SplitGroupMaterialNeeds $splitGroupMaterialNeeds,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectStepResource
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

        if (!$step->isSplit()) {
            throw new BadRequestHttpException('Step is not part of a split group');
        }

        $splitGroupId = $step->getSplitGroupId();

        // Find all steps in the same split group
        $siblings = [];
        $totalRuns = 0;
        $totalQuantity = 0;

        foreach ($project->getSteps() as $s) {
            if ($s->getSplitGroupId() === $splitGroupId) {
                $siblings[] = $s;
                $totalRuns += $s->getRuns();
                $totalQuantity += $s->getQuantity();
            }
        }

        if (count($siblings) <= 1) {
            throw new BadRequestHttpException('No siblings to merge');
        }

        // Keep the first step (lowest splitIndex), remove the rest
        usort($siblings, fn ($a, $b) => $a->getSplitIndex() <=> $b->getSplitIndex());
        $keepStep = $siblings[0];

        try {
            $this->splitGroupMaterialNeeds->applyChangeOfJobs(
                $project,
                $keepStep,
                array_map(static fn (IndustryProjectStep $sibling): int => $sibling->getRuns(), $siblings),
                [$totalRuns],
            );
        } catch (BlueprintProductMissingFromSdeException $missingProduct) {
            throw new BadRequestHttpException($missingProduct->getMessage(), $missingProduct);
        }

        // Merge all job matches and purchases into the kept step
        for ($i = 1; $i < count($siblings); $i++) {
            $sibling = $siblings[$i];

            foreach ($sibling->getJobMatches()->toArray() as $match) {
                $sibling->getJobMatches()->removeElement($match);
                $keepStep->addJobMatch($match);
            }

            foreach ($sibling->getPurchases()->toArray() as $purchase) {
                $sibling->getPurchases()->removeElement($purchase);
                $keepStep->addPurchase($purchase);
            }

            $project->getSteps()->removeElement($sibling);
            $this->entityManager->remove($sibling);
        }

        // Update the kept step
        $keepStep->setRuns($totalRuns);
        $keepStep->setQuantity($totalQuantity);
        $keepStep->setSplitGroupId(null);
        $keepStep->setSplitIndex(0);
        $keepStep->setTotalGroupRuns(null);

        $this->entityManager->flush();

        return $this->mapper->stepToResource($keepStep);
    }
}
