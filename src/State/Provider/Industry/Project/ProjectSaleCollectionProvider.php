<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectSaleResource>
 */
class ProjectSaleCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly IndustryProjectSaleRepository $saleRepository,
        private readonly CachedWalletJournalEntryRepository $journalRepository,
    ) {
    }

    /**
     * @return ProjectSaleResource[]
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

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $sales = $this->saleRepository->findByProjectForListing($project);

        $feesByContractId = $this->journalRepository->contractFeesByContractId(array_values(array_filter(array_map(
            static fn (IndustryProjectSale $sale): ?int => $sale->getContractId(),
            $sales,
        ))));

        return array_map(
            static fn (IndustryProjectSale $sale): ProjectSaleResource => ProjectSaleResource::fromSale(
                $sale,
                $user,
                $sale->getContractId() === null ? null : $feesByContractId[$sale->getContractId()] ?? null,
            ),
            $sales,
        );
    }
}
