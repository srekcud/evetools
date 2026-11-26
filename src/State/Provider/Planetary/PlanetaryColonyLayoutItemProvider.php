<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryColonyLayoutResource;
use App\Entity\User;
use App\Repository\PlanetaryColonyLayoutRepository;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutSummarizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<PlanetaryColonyLayoutResource>
 */
final readonly class PlanetaryColonyLayoutItemProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryColonyLayoutRepository $layoutRepository,
        private PlanetaryColonyLayoutSummarizer $summarizer,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryColonyLayoutResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = (string) $uriVariables['id'];

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Colony layout not found');
        }

        $layout = $this->layoutRepository->findOneByUserAndId($user, Uuid::fromString($id));

        if ($layout === null) {
            throw new NotFoundHttpException('Colony layout not found');
        }

        return PlanetaryColonyLayoutResource::fromEntity($layout, $this->summarizer->summarize($layout->getTemplate()));
    }
}
