<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\Entity\User;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ResolvesSaleOfProject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectSaleResource>
 */
final readonly class ProjectSaleItemProvider implements ProviderInterface
{
    use ResolvesSaleOfProject;

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectSaleRepository $saleRepository,
        private ProjectAccessChecker $accessChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectSaleResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectOf($this->projectRepository, $uriVariables);
        $this->accessChecker->assertAdminOrOwner($user, $project);

        return ProjectSaleResource::fromSale($this->saleOf($this->saleRepository, $project, $uriVariables), $user);
    }
}
