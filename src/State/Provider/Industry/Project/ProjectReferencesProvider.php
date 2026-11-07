<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectReferencesResource;
use App\Entity\User;
use App\Service\Industry\Project\ProjectReference;
use App\Service\Industry\Project\ProjectReferenceReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectReferencesResource>
 */
final readonly class ProjectReferencesProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private ProjectReferenceReader $referenceReader,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectReferencesResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new ProjectReferencesResource();
        $resource->rows = array_map(
            static fn (ProjectReference $reference): array => [
                'productTypeId' => $reference->productTypeId,
                'projectId' => $reference->projectId,
                'completedAt' => $reference->completedAt->format(\DATE_ATOM),
                'units' => $reference->units,
                'unitCost' => $reference->unitCost,
                'marginPercent' => $reference->marginPercent,
            ],
            $this->referenceReader->read($user, $this->clock->now()),
        );

        return $resource;
    }
}
