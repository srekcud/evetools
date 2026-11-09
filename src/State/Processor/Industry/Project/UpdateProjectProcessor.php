<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\ApiResource\Input\Industry\Project\ProjectFees;
use App\ApiResource\Input\Industry\Project\UpdateProjectInput;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectSellStructures;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateProjectInput, IndustryProjectResource>
 */
class UpdateProjectProcessor implements ProcessorInterface
{
    private const array VALID_TRANSITIONS = [
        'draft' => ['published', 'in_progress'],
        'published' => ['in_progress'],
        'in_progress' => ['selling'],
        'selling' => ['completed'],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly IndustryProjectService $projectService,
        private readonly ProjectSellStructures $sellStructures,
        private readonly LineReservationService $lineReservations,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IndustryProjectResource
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

        assert($data instanceof UpdateProjectInput);

        $oldStatus = $project->getStatus();
        $previousBrokerFeePercent = $project->getBrokerFeePercent();
        $previousSalesTaxPercent = $project->getSalesTaxPercent();

        $feesAreChanged = $data->brokerFeePercent !== null || $data->salesTaxPercent !== null;

        if ($feesAreChanged && $project->isCompleted()) {
            throw new BadRequestHttpException('Cannot change the fees of a completed project');
        }

        if ($feesAreChanged) {
            $effectiveBrokerFeePercent = $data->brokerFeePercent ?? $project->getBrokerFeePercent();
            $effectiveSalesTaxPercent = $data->salesTaxPercent ?? $project->getSalesTaxPercent();
            if ($effectiveBrokerFeePercent !== null && $effectiveSalesTaxPercent !== null) {
                ProjectFees::rejectEffectiveFeesAboveMaximum($effectiveBrokerFeePercent, $effectiveSalesTaxPercent);
            }
        }

        $this->changeTheBlacklist($project, $data);
        $this->changeTheSellStructure($user, $project, $data);

        if ($data->name !== null) {
            $name = trim($data->name);
            $project->setName($name !== '' ? $name : null);
        }

        if ($data->containerName !== null) {
            $project->setContainerName($data->containerName !== '' ? $data->containerName : null);
        }

        if ($data->notes !== null) {
            $project->setNotes($data->notes !== '' ? $data->notes : null);
        }

        if ($data->private !== null) {
            $project->setPrivate($data->private);
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

            $newStatus = ProjectStatus::from($data->status);
            $project->setStatus($newStatus);

            if ($newStatus === ProjectStatus::Completed) {
                $project->setCompletedAt(new \DateTimeImmutable());
                $this->lineReservations->releaseAllOfProject($project);
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

        if ($previousBrokerFeePercent !== $project->getBrokerFeePercent() || $previousSalesTaxPercent !== $project->getSalesTaxPercent()) {
            $this->mercurePublisher->publishGroupProjectEvent(
                $project->getId()->toRfc4122(),
                'fees_updated',
                [
                    'brokerFeePercent' => $project->getBrokerFeePercent(),
                    'salesTaxPercent' => $project->getSalesTaxPercent(),
                    'previousBrokerFeePercent' => $previousBrokerFeePercent,
                    'previousSalesTaxPercent' => $previousSalesTaxPercent,
                ],
            );
        }

        $membership = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
        ]);

        return $this->mapper->projectToResource($project, $membership);
    }

    private function changeTheSellStructure(User $user, IndustryProject $project, UpdateProjectInput $data): void
    {
        if (null === $data->sellStructureId) {
            return;
        }

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('Cannot change the sell structure of a completed project');
        }

        try {
            $sellStructure = $this->sellStructures->choose($user, $data->sellStructureId);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $project->setSellStructure($sellStructure->id, $sellStructure->name);
    }

    private function changeTheBlacklist(IndustryProject $project, UpdateProjectInput $data): void
    {
        $groupsAreSent = $data->blacklistGroupIds !== null || $data->blacklistCategoryKeys !== null;
        $typesAreSent = $data->blacklistTypeIds !== null;

        if (!$groupsAreSent && !$typesAreSent) {
            return;
        }

        if ($project->getStatus() !== ProjectStatus::Draft) {
            throw new BadRequestHttpException('The blacklist of a project can only be changed while it is a draft');
        }

        if ($groupsAreSent) {
            $project->setBlacklistGroupIds(IndustryBlacklistService::resolveGroupIds($data->blacklistGroupIds ?? [], $data->blacklistCategoryKeys ?? []));
        }

        if ($data->blacklistTypeIds !== null) {
            $project->setBlacklistTypeIds($data->blacklistTypeIds);
        }

        try {
            $this->projectService->buildBom($project, $project->getOwner());
        } catch (\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }
}
