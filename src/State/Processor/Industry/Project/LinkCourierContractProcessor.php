<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectCourierResource;
use App\ApiResource\Input\Industry\Project\LinkCourierContractInput;
use App\Entity\IndustryProjectCourierContract;
use App\Entity\User;
use App\Repository\IndustryProjectCourierContractRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\Courier\CourierContract;
use App\Service\Industry\Project\Courier\CourierContractReader;
use App\Service\Industry\Project\Courier\CourierOverviewBuilder;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectCourierResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<LinkCourierContractInput, ProjectCourierResource>
 */
final readonly class LinkCourierContractProcessor implements ProcessorInterface
{
    private const string CONTRACT_NOT_LINKABLE_MESSAGE = 'The contract is not a courier contract of your characters issued after the creation of the project';

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectCourierContractRepository $linkRepository,
        private ProjectAccessChecker $accessChecker,
        private CourierContractReader $contractReader,
        private CourierOverviewBuilder $overviewBuilder,
        private ProjectCourierResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private DerivedContributionService $derivedContributions,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectCourierResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        \assert($data instanceof LinkCourierContractInput);

        $projectId = $uriVariables['projectId'] ?? null;
        if (null === $projectId) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertOwner($user, $project);

        $contract = $this->linkableContract($user, $project->getCreatedAt(), $data->contractId);
        $link = $this->linkRepository->findOneByProject($project) ?? (new IndustryProjectCourierContract())->setProject($project);
        $link->link($contract->contractId, $contract->characterId, new \DateTimeImmutable());

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $overview = $this->overviewBuilder->build($project, $user);
        $this->derivedContributions->syncCourier($project, $overview->contract);

        return $this->mapper->toResource($project, $overview);
    }

    private function linkableContract(User $owner, \DateTimeImmutable $issuedAfter, int $contractId): CourierContract
    {
        foreach ($this->contractReader->read($owner, $issuedAfter)->contracts as $contract) {
            if ($contract->contractId === $contractId) {
                return $contract;
            }
        }

        throw new BadRequestHttpException(self::CONTRACT_NOT_LINKABLE_MESSAGE);
    }
}
