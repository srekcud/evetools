<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\SplitStepInput;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<SplitStepInput, array>
 */
class SplitStepProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return ProjectStepResource[]
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $step = $this->stepRepository->find(Uuid::fromString($uriVariables['stepId']));

        if ($step === null || $step->getProject() !== $project) {
            throw new NotFoundHttpException('Step not found');
        }

        if (!$data instanceof SplitStepInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        $numberOfJobs = $data->numberOfJobs;
        $totalRuns = $step->getRuns();

        if ($totalRuns < $numberOfJobs) {
            throw new BadRequestHttpException("Cannot split {$totalRuns} runs into {$numberOfJobs} jobs");
        }

        // If step is already in a split group, merge first
        if ($step->isSplit()) {
            throw new BadRequestHttpException('Step is already split. Merge first before re-splitting.');
        }

        $splitGroupId = Uuid::v4()->toRfc4122();
        $baseRunsPerJob = (int) floor($totalRuns / $numberOfJobs);
        $remainder = $totalRuns - ($baseRunsPerJob * $numberOfJobs);

        // Convert the original step into the first split
        $step->setSplitGroupId($splitGroupId);
        $step->setSplitIndex(0);
        $runsForFirst = $baseRunsPerJob + ($remainder > 0 ? 1 : 0);
        $step->setRuns($runsForFirst);
        $step->setQuantity($runsForFirst); // For reactions, quantity may differ from runs
        $step->setTotalGroupRuns($totalRuns);

        $results = [$step];

        // Create additional split steps
        for ($i = 1; $i < $numberOfJobs; $i++) {
            $runsForThisJob = $baseRunsPerJob + ($i < $remainder ? 1 : 0);

            $newStep = new IndustryProjectStep();
            $newStep->setBlueprintTypeId($step->getBlueprintTypeId());
            $newStep->setProductTypeId($step->getProductTypeId());
            $newStep->setQuantity($runsForThisJob);
            $newStep->setRuns($runsForThisJob);
            $newStep->setDepth($step->getDepth());
            $newStep->setActivityType($step->getActivityType());
            $newStep->setSortOrder($step->getSortOrder());
            $newStep->setSplitGroupId($splitGroupId);
            $newStep->setSplitIndex($i);
            $newStep->setTotalGroupRuns($totalRuns);
            $newStep->setMeLevel($step->getMeLevel());
            $newStep->setTeLevel($step->getTeLevel());
            $newStep->setStructureConfig($step->getStructureConfig());
            $newStep->setJobMatchMode($step->getJobMatchMode());

            $project->addStep($newStep);
            $results[] = $newStep;
        }

        $this->entityManager->flush();

        return array_map(fn ($s) => $this->mapper->stepToResource($s), $results);
    }
}
