<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\ApiResource\Input\Industry\AssignUnassignedJobInput;
use App\Entity\User;
use App\Repository\UnassignedJobRepository;
use App\Service\Industry\Project\AssignableCandidate;
use App\Service\Industry\Project\ManualJobLinker;
use App\Service\Industry\Project\ProjectJobActivityRecorder;
use App\Service\Industry\Project\UnassignedJobCandidates;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<AssignUnassignedJobInput, ProjectJobMatchResource>
 */
class AssignUnassignedJobProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UnassignedJobRepository $unassignedJobRepository,
        private readonly UnassignedJobCandidates $candidates,
        private readonly ManualJobLinker $manualJobLinker,
        private readonly ProjectJobActivityRecorder $activityRecorder,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectJobMatchResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $unassignedJob = $this->unassignedJobRepository->findOneOfUser($user, (int) $uriVariables['esiJobId']);
        if ($unassignedJob === null || $unassignedJob->getDismissedAt() !== null) {
            throw new NotFoundHttpException('Unassigned job not found');
        }

        \assert($data instanceof AssignUnassignedJobInput);
        $chosenLineId = strtolower((string) $data->lineId);

        $chosen = array_find(
            $this->candidates->assignableTo($user, $unassignedJob),
            static fn (AssignableCandidate $candidate): bool => $candidate->line->getId()->toRfc4122() === $chosenLineId,
        );
        if ($chosen === null) {
            throw new BadRequestHttpException('The line is not a candidate of this job');
        }

        $match = $this->manualJobLinker->link($unassignedJob->getJob(), $chosen->line);
        $this->activityRecorder->recordJobsOf($chosen->project);

        return ProjectJobMatchResource::fromEntity($match);
    }
}
