<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\Entity\CachedIndustryJob;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

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
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryJobMatcher $jobMatcher,
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

        /** @var LinkJobInput $data */
        $esiJobId = $data->esiJobId;

        // A job installed by another user's character is answered like an unknown job
        $job = $this->jobRepository->findByJobId($esiJobId);
        if ($job === null || !$this->isOwnedBy($job, $user)) {
            throw new BadRequestHttpException("ESI job {$esiJobId} not found");
        }

        // Validate that the blueprint matches
        if ($job->getBlueprintTypeId() !== $step->getBlueprintTypeId()) {
            throw new BadRequestHttpException('Job blueprint does not match step blueprint');
        }

        if ($job->hasNoOutput()) {
            throw new BadRequestHttpException("ESI job {$esiJobId} has no output");
        }

        if (!$this->jobMatcher->isJobOfStepActivity($job, $step)) {
            throw new BadRequestHttpException('Job activity does not match step activity');
        }

        // Validate that the job is not already linked to another step
        $existingMatch = $this->entityManager->createQuery(
            'SELECT m FROM App\Entity\IndustryStepJobMatch m WHERE m.esiJobId = :esiJobId'
        )->setParameter('esiJobId', $esiJobId)->getOneOrNullResult();

        if ($existingMatch !== null) {
            throw new BadRequestHttpException("ESI job {$esiJobId} is already linked to a step");
        }

        $this->jobMatcher->linkJobToStep($job, $step);

        return $this->mapper->stepToResource($step);
    }

    private function isOwnedBy(CachedIndustryJob $job, User $user): bool
    {
        return $job->getCharacter()->getUser() === $user;
    }
}
