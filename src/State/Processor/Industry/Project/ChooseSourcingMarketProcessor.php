<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectBomLineSourcingMarketResource;
use App\ApiResource\Input\Industry\Project\ChooseSourcingMarketInput;
use App\Entity\User;
use App\Enum\SourcingMarket;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Industry\Project\SourcingMarketAccess;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ChooseSourcingMarketInput, ProjectBomLineSourcingMarketResource>
 */
final readonly class ChooseSourcingMarketProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private SourcingMarketAccess $access,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectBomLineSourcingMarketResource
    {
        \assert($data instanceof ChooseSourcingMarketInput);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->access->assertMayChoose($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'] ?? null, 'BOM line not found'));
        if (null === $line || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM line not found');
        }

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('The project is completed, the market of its lines can no longer be chosen');
        }

        if ($line->isJob()) {
            throw new BadRequestHttpException('Only a material line has a market');
        }

        $market = SourcingMarket::tryFrom($data->market ?? '');
        if (null === $market) {
            throw new BadRequestHttpException('The market must be "jita" or "structure"');
        }

        $line->setSourcingMarket($market);
        $this->entityManager->flush();

        BomLineMarkNotifier::notifyLineChanged($this->mercurePublisher, $project, $line);

        return ProjectBomLineSourcingMarketResource::fromLine($line);
    }
}
