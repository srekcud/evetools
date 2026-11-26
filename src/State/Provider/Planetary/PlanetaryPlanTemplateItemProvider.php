<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<PlanetaryPlanTemplateResource>
 */
final readonly class PlanetaryPlanTemplateItemProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryPlanTemplateRepository $templateRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlanTemplateResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = (string) $uriVariables['id'];

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Plan template not found');
        }

        $template = $this->templateRepository->findOneByUserAndId($user, Uuid::fromString($id));

        if ($template === null) {
            throw new NotFoundHttpException('Plan template not found');
        }

        return PlanetaryPlanTemplateResource::fromEntity($template);
    }
}
