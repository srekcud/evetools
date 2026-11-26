<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryColonyLayoutLibraryResource;
use App\Entity\PlanetaryColonyLayout;
use App\Entity\User;
use App\Repository\PlanetaryColonyLayoutRepository;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutSummarizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryColonyLayoutLibraryResource>
 */
final readonly class PlanetaryColonyLayoutLibraryProvider implements ProviderInterface
{
    private const int LIBRARY_LIMIT = 200;

    public function __construct(
        private Security $security,
        private PlanetaryColonyLayoutRepository $layoutRepository,
        private PlanetaryColonyLayoutSummarizer $summarizer,
    ) {
    }

    /**
     * @return list<PlanetaryColonyLayoutLibraryResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $viewerId = (string) $user->getId();

        return array_map(
            fn (PlanetaryColonyLayout $layout): PlanetaryColonyLayoutLibraryResource => $this->toRow($layout, $viewerId === (string) $layout->getUser()->getId()),
            $this->layoutRepository->findPublic(self::LIBRARY_LIMIT),
        );
    }

    private function toRow(PlanetaryColonyLayout $layout, bool $mine): PlanetaryColonyLayoutLibraryResource
    {
        $publishedAt = $layout->getPublishedAt();
        if ($publishedAt === null) {
            throw new \LogicException('A public colony layout has no publication date');
        }

        $row = new PlanetaryColonyLayoutLibraryResource();
        $row->id = (string) $layout->getId();
        $row->name = $layout->getName();
        $row->summary = $this->summarizer->summarize($layout->getTemplate());
        $row->gameTemplate = $layout->getTemplate();
        $row->publishedAt = $publishedAt->format('c');
        $row->mine = $mine;

        return $row;
    }
}
