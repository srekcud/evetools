<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ManualJobLinker;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<LinkJobInput, ProjectJobMatchResource>
 */
class LinkJobProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectBomLineRepository $bomLineRepository,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ManualJobLinker $manualJobLinker,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectJobMatchResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'], 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $jobOwners = $this->accessChecker->usersWhoseJobsCanBeLinkedBy($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'], 'BOM line not found'));
        if ($line === null || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM line not found');
        }

        \assert($data instanceof LinkJobInput);
        $esiJobId = $data->esiJobId;

        $job = $this->jobRepository->findByJobId($esiJobId);
        if ($job === null || !\in_array($job->getCharacter()->getUser(), $jobOwners, true)) {
            throw new BadRequestHttpException("ESI job {$esiJobId} not found");
        }

        $match = $this->manualJobLinker->link($job, $line, $data->runs);

        return ProjectJobMatchResource::fromEntity($match);
    }
}
