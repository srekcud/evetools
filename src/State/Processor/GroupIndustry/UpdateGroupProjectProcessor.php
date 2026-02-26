<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\GroupIndustry\UpdateGroupProjectInput;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateGroupProjectInput, GroupIndustryProjectResource>
 */
class UpdateGroupProjectProcessor implements ProcessorInterface
{
    private const array VALID_TRANSITIONS = [
        'published' => ['in_progress'],
        'in_progress' => ['selling'],
        'selling' => ['completed'],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupProjectAccessChecker $accessChecker,
        private readonly GroupIndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustryProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        assert($data instanceof UpdateGroupProjectInput);

        $oldStatus = $project->getStatus();

        if ($data->name !== null) {
            $name = trim($data->name);
            $project->setName($name !== '' ? $name : null);
        }

        if ($data->containerName !== null) {
            $project->setContainerName($data->containerName !== '' ? $data->containerName : null);
        }

        if ($data->brokerFeePercent !== null) {
            $project->setBrokerFeePercent($data->brokerFeePercent);
        }

        if ($data->salesTaxPercent !== null) {
            $project->setSalesTaxPercent($data->salesTaxPercent);
        }

        if ($data->status !== null) {
            $currentStatus = $project->getStatus()->value;
            $allowedTargets = self::VALID_TRANSITIONS[$currentStatus] ?? [];

            if (!in_array($data->status, $allowedTargets, true)) {
                throw new BadRequestHttpException(
                    sprintf('Cannot transition from "%s" to "%s"', $currentStatus, $data->status),
                );
            }

            $newStatus = GroupProjectStatus::from($data->status);
            $project->setStatus($newStatus);

            if ($newStatus === GroupProjectStatus::Completed) {
                $project->setCompletedAt(new \DateTimeImmutable());
            }
        }

        $project->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        if ($oldStatus !== $project->getStatus()) {
            $this->mercurePublisher->publishGroupProjectEvent(
                $project->getId()->toRfc4122(),
                'status_changed',
                [
                    'oldStatus' => $oldStatus->value,
                    'newStatus' => $project->getStatus()->value,
                ],
            );
        }

        $membership = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
        ]);

        return $this->mapper->projectToResource($project, $membership);
    }
}
