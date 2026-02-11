<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\Entity\IndustryStepJobMatch;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStepJobMatchRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectService;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<LinkJobInput, ProjectStepResource>
 */
class LinkJobProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly IndustryStepJobMatchRepository $matchRepository,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryProjectService $projectService,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectStepResource
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

        /** @var LinkJobInput $data */
        $esiJobId = $data->esiJobId;

        // Validate that the job exists
        $job = $this->jobRepository->findByJobId($esiJobId);
        if ($job === null) {
            throw new BadRequestHttpException("ESI job {$esiJobId} not found");
        }

        // Validate that the blueprint matches
        if ($job->getBlueprintTypeId() !== $step->getBlueprintTypeId()) {
            throw new BadRequestHttpException('Job blueprint does not match step blueprint');
        }

        // Validate that the job is not already linked to another step
        $existingMatch = $this->entityManager->createQuery(
            'SELECT m FROM App\Entity\IndustryStepJobMatch m WHERE m.esiJobId = :esiJobId'
        )->setParameter('esiJobId', $esiJobId)->getOneOrNullResult();

        if ($existingMatch !== null) {
            throw new BadRequestHttpException("ESI job {$esiJobId} is already linked to a step");
        }

        // Create the match
        $match = new IndustryStepJobMatch();
        $match->setEsiJobId($esiJobId);
        $match->setCost($job->getCost());
        $match->setStatus($job->getStatus());
        $match->setEndDate($job->getEndDate());
        $match->setRuns($job->getRuns());
        $match->setCharacterName($job->getCharacter()->getName());

        // Capture facility info from ESI job
        $stationId = $job->getStationId();
        if ($stationId !== null) {
            $match->setFacilityId($stationId);
            $match->setFacilityName($this->calculationService->resolveFacilityName($stationId));

            $currentConfig = $step->getStructureConfig();
            if ($currentConfig?->getLocationId() !== $stationId) {
                $facilityConfig = $this->structureConfigRepository
                    ->findByUserAndLocationId($user, $stationId);
                if ($facilityConfig !== null && $facilityConfig->getId() !== $currentConfig?->getId()) {
                    // Record what was planned before correction
                    $match->setPlannedStructureName($currentConfig?->getName() ?? 'Aucune structure');
                    $currentBonus = $this->calculationService->getStructureBonusForStep($step);
                    $match->setPlannedMaterialBonus($currentBonus['materialBonus']);

                    // Auto-correct
                    $step->setStructureConfig($facilityConfig);
                    $this->entityManager->flush();
                    $this->projectService->recalculateStepQuantities($project);
                }
            }
        }

        $step->addJobMatch($match);

        // Adapt step runs if total matched runs differ from expected
        $totalMatchedRuns = 0;
        foreach ($step->getJobMatches() as $m) {
            $totalMatchedRuns += $m->getRuns();
        }
        $runsChanged = $totalMatchedRuns > 0 && $totalMatchedRuns !== $step->getRuns();
        if ($runsChanged) {
            $activityId = $step->getActivityType() === 'reaction' ? 11 : 1;
            $product = $this->entityManager->getRepository(\App\Entity\Sde\IndustryActivityProduct::class)
                ->findOneBy(['typeId' => $step->getBlueprintTypeId(), 'activityId' => $activityId]);
            $outputPerRun = $product?->getQuantity() ?? 1;

            $step->setRuns($totalMatchedRuns);
            $step->setQuantity($totalMatchedRuns * $outputPerRun);
        }

        $this->entityManager->flush();

        if ($runsChanged) {
            $this->projectService->recalculateStepQuantities($project);
        }

        return $this->mapper->stepToResource($step);
    }
}
