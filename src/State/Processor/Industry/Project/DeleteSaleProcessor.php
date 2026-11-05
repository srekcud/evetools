<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\Entity\User;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ResolvesSaleOfProject;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ProjectSaleResource, void>
 */
final readonly class DeleteSaleProcessor implements ProcessorInterface
{
    use ResolvesSaleOfProject;

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectSaleRepository $saleRepository,
        private ProjectAccessChecker $accessChecker,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectOf($this->projectRepository, $uriVariables);
        $this->accessChecker->assertAdminOrOwner($user, $project);

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('Cannot delete a sale on a completed project');
        }

        $sale = $this->saleOf($this->saleRepository, $project, $uriVariables);
        $saleId = $sale->getId()->toRfc4122();

        $this->entityManager->remove($sale);
        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'sale_deleted',
            ['saleId' => $saleId],
        );
    }
}
