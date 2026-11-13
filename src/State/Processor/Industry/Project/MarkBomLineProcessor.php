<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectBomLineMarkResource;
use App\ApiResource\Input\Industry\Project\MarkBomLineInput;
use App\Entity\IndustryProjectBomLineMark;
use App\Entity\User;
use App\Enum\BomLineMarkState;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<MarkBomLineInput, ProjectBomLineMarkResource>
 */
final readonly class MarkBomLineProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private ProjectAccessChecker $accessChecker,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectBomLineMarkResource
    {
        \assert($data instanceof MarkBomLineInput);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertOwner($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'] ?? null, 'BOM line not found'));
        if (null === $line || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM line not found');
        }

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('The project is completed, its lines can no longer be marked');
        }

        if (!$line->isJob()) {
            throw new BadRequestHttpException('Only a job line can be marked');
        }

        $state = BomLineMarkState::tryFrom($data->state ?? '');
        if (null === $state) {
            throw new BadRequestHttpException('The state must be "launched" or "done"');
        }

        $lineRuns = $line->getRuns() ?? throw new \LogicException('The job line has no runs.');
        $runs = $data->runs ?? throw new \LogicException('The runs are required.');
        if ($runs < 1 || $runs > $lineRuns) {
            throw new BadRequestHttpException(sprintf('The runs must be between 1 and %d', $lineRuns));
        }

        $markedAt = new \DateTimeImmutable();
        $existing = $line->getMark();
        if (null === $existing) {
            $line->setMark(new IndustryProjectBomLineMark($state, $runs, $markedAt));
        } else {
            $existing->replace($state, $runs, $markedAt);
        }

        $this->entityManager->flush();

        BomLineMarkNotifier::notifyLineChanged($this->mercurePublisher, $project, $line);

        return ProjectBomLineMarkResource::fromLine($line);
    }
}
