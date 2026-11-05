<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\Entity\User;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectPurchaseResource>
 */
class PurchaseDeleteProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectPurchaseRepository $purchaseRepository,
        private readonly GroupProjectAccessChecker $accessChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectPurchaseResource
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

        $purchase = $this->purchaseRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Purchase not found'));
        if ($purchase === null || $purchase->getBomLine()->getProject() !== $project) {
            throw new NotFoundHttpException('Purchase not found');
        }

        return ProjectPurchaseResource::fromEntity($purchase);
    }
}
