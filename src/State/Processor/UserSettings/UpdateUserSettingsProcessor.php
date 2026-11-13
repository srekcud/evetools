<?php

declare(strict_types=1);

namespace App\State\Processor\UserSettings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\UserSettings\UserSettingsResource;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedStructureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<UserSettingsResource, UserSettingsResource>
 */
class UpdateUserSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
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

        $this->refuseInaccessiblePreferredStructure($user, $data->preferredMarketStructureId);

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
        $resource->effectiveMarketStructureId = $user->getEffectiveMarketStructureId() ?? $this->defaultMarketStructureId;
        $resource->effectiveMarketStructureName = $user->getEffectiveMarketStructureName() ?? $this->defaultMarketStructureName;
        $resource->marketStructures = $user->getMarketStructures();

        return $resource;
    }

    private function refuseInaccessiblePreferredStructure(User $user, ?int $structureId): void
    {
        if ($structureId === null || $structureId === $user->getPreferredMarketStructureId()) {
            return;
        }

        if ($this->cachedStructureRepository->findByStructureId($structureId) !== null) {
            return;
        }

        if ($this->cachedAssetRepository->userHasPersonalAssetAtLocation($user, $structureId)) {
            return;
        }

        $message = 'This structure is not known to any of your characters.';

        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, $message, [], $structureId, 'preferredMarketStructureId', $structureId),
        ]));
    }
}
