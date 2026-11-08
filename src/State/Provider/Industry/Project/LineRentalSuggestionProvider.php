<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\LineRentalSuggestionResource;
use App\Entity\User;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\LineRentalSuggester;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<LineRentalSuggestionResource>
 */
final readonly class LineRentalSuggestionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private ProjectAccessChecker $accessChecker,
        private LineRentalSuggester $suggester,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LineRentalSuggestionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        $lineId = $uriVariables['id'] ?? null;

        if ($projectId === null || $lineId === null) {
            throw new NotFoundHttpException('Line not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $line = $this->bomLineRepository->findOneBy([
            'id' => UuidOrNotFound::of($lineId, 'Line not found'),
            'project' => $project,
        ]);
        if ($line === null) {
            throw new NotFoundHttpException('Line not found');
        }

        $suggestion = $this->suggester->suggest($user, $line);

        $resource = new LineRentalSuggestionResource();
        $resource->id = $line->getId()->toRfc4122();
        $resource->bomItemId = $line->getId()->toRfc4122();
        $resource->suggestedValue = $suggestion->suggestedValue;
        $resource->unknownReason = $suggestion->unknownReason?->value;
        $resource->category = $suggestion->category?->value;
        $resource->days = $suggestion->days;
        $resource->runs = $suggestion->runs;
        $resource->dayFactor = $suggestion->dayFactor;
        $resource->ratePerRun = $suggestion->ratePerRun;

        return $resource;
    }
}
