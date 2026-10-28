<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\Entity\User;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use App\Service\GroupIndustry\ResolvesSaleOfProject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<GroupIndustrySaleResource>
 */
final readonly class GroupSaleItemProvider implements ProviderInterface
{
    use ResolvesSaleOfProject;

    public function __construct(
        private Security $security,
        private GroupIndustryProjectRepository $projectRepository,
        private GroupIndustrySaleRepository $saleRepository,
        private GroupProjectAccessChecker $accessChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GroupIndustrySaleResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectOf($this->projectRepository, $uriVariables);
        $this->accessChecker->assertAdminOrOwner($user, $project);

        return GroupIndustrySaleResource::fromSale($this->saleOf($this->saleRepository, $project, $uriVariables));
    }
}
