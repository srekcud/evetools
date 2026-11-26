<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryColonyLayoutResource;
use App\Entity\PlanetaryColonyLayout;
use App\Entity\User;
use App\Repository\PlanetaryColonyLayoutRepository;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutSummarizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryColonyLayoutResource>
 */
final readonly class PlanetaryColonyLayoutCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryColonyLayoutRepository $layoutRepository,
        private PlanetaryColonyLayoutSummarizer $summarizer,
    ) {
    }

    /**
     * @return list<PlanetaryColonyLayoutResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return array_map(
            fn (PlanetaryColonyLayout $layout): PlanetaryColonyLayoutResource => PlanetaryColonyLayoutResource::fromEntity($layout, $this->summarizer->summarize($layout->getTemplate())),
            $this->layoutRepository->findByUser($user),
        );
    }
}
