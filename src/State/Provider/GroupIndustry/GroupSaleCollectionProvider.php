<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<GroupIndustrySaleResource>
 */
class GroupSaleCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustrySaleRepository $saleRepository,
    ) {
    }

    /**
     * @return GroupIndustrySaleResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->assertUserCanAccessProject($user, $project);

        $sales = $this->saleRepository->findBy(
            ['project' => $project],
            ['soldAt' => 'DESC'],
        );

        return array_map(
            fn (GroupIndustrySale $sale) => $this->mapToResource($sale),
            $sales,
        );
    }

    private function assertUserCanAccessProject(User $user, \App\Entity\GroupIndustryProject $project): void
    {
        if ($project->getOwner() === $user) {
            return;
        }

        $member = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => GroupMemberStatus::Accepted,
        ]);
        if ($member !== null) {
            return;
        }

        $userCorpId = $user->getCorporationId();
        $ownerCorpId = $project->getOwner()->getCorporationId();
        if ($userCorpId !== null && $userCorpId === $ownerCorpId) {
            return;
        }

        throw new AccessDeniedHttpException('You do not have access to this project');
    }

    private function mapToResource(GroupIndustrySale $sale): GroupIndustrySaleResource
    {
        $resource = new GroupIndustrySaleResource();
        $resource->id = $sale->getId()->toRfc4122();
        $resource->typeId = $sale->getTypeId();
        $resource->typeName = $sale->getTypeName();
        $resource->quantity = $sale->getQuantity();
        $resource->unitPrice = $sale->getUnitPrice();
        $resource->totalPrice = $sale->getTotalPrice();
        $resource->venue = $sale->getVenue();
        $resource->soldAt = $sale->getSoldAt()->format(\DateTimeInterface::ATOM);
        $resource->recordedByCharacterName = $sale->getRecordedBy()->getMainCharacter()?->getName() ?? 'Unknown';
        $resource->createdAt = $sale->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
