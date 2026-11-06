<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\Entity\CachedIndustryJob;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\Project\DerivedContributionService;
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
        private readonly IndustryProjectJobMatchRepository $jobMatchRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly IndustryJobMatcher $jobMatcher,
        private readonly DerivedContributionService $derivedContributionService,
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

        $this->accessChecker->assertOwner($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'], 'BOM line not found'));
        if ($line === null || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM line not found');
        }

        \assert($data instanceof LinkJobInput);
        $esiJobId = $data->esiJobId;

        $job = $this->jobRepository->findByJobId($esiJobId);
        if ($job === null || !$this->isOwnedBy($job, $user)) {
            throw new BadRequestHttpException("ESI job {$esiJobId} not found");
        }

        if (!$this->jobMatcher->isJobOfBomLineActivity($job, $line)) {
            throw new BadRequestHttpException('Job activity does not match BOM line activity');
        }

        if ($job->getBlueprintTypeId() !== $this->productRepository->producerOf($line->getTypeId())->getTypeId()) {
            throw new BadRequestHttpException('Job blueprint does not match BOM line blueprint');
        }

        if ($job->hasNoOutput()) {
            throw new BadRequestHttpException("ESI job {$esiJobId} has no output");
        }

        if ($this->jobMatchRepository->findOneBy(['esiJobId' => $esiJobId]) !== null) {
            throw new BadRequestHttpException("ESI job {$esiJobId} is already linked to a BOM line");
        }

        $match = $this->jobMatcher->linkJobToBomLine($job, $line);
        $this->derivedContributionService->refreshLine($line);

        return ProjectJobMatchResource::fromEntity($match);
    }

    private function isOwnedBy(CachedIndustryJob $job, User $user): bool
    {
        return $job->getCharacter()->getUser() === $user;
    }
}
