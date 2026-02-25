<?php

declare(strict_types=1);

namespace App\State\Processor\UserSettings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\UserSettings\UserSettingsResource;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UserSettingsResource, UserSettingsResource>
 */
class UpdateUserSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $defaultMarketStructureId,
        private readonly string $defaultMarketStructureName,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserSettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof UserSettingsResource);

        // Apply merged values from the PATCH payload
        $user->setPreferredMarketStructureId($data->preferredMarketStructureId);
        $user->setPreferredMarketStructureName($data->preferredMarketStructureName);
        $user->setMarketStructures($data->marketStructures);

        // If ID is cleared, also clear the name
        if ($data->preferredMarketStructureId === null) {
            $user->setPreferredMarketStructureName(null);
        }

        $this->entityManager->flush();

        // Return updated settings
        $resource = new UserSettingsResource();
        $resource->preferredMarketStructureId = $user->getPreferredMarketStructureId();
        $resource->preferredMarketStructureName = $user->getPreferredMarketStructureName();
        $resource->defaultMarketStructureId = $this->defaultMarketStructureId;
        $resource->defaultMarketStructureName = $this->defaultMarketStructureName;
        $resource->effectiveMarketStructureId = $user->getPreferredMarketStructureId() ?? $this->defaultMarketStructureId;
        $resource->effectiveMarketStructureName = $user->getPreferredMarketStructureName() ?? $this->defaultMarketStructureName;
        $resource->marketStructures = $user->getMarketStructures();

        return $resource;
    }
}
