<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StructureConfigResource;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateStructureInput, StructureConfigResource>
 */
class CreateStructureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StructureConfigResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateStructureInput);

        if ($data->isDefault) {
            $this->structureConfigRepository->clearDefaultForUser($user);
        }

        $structure = $this->restoreOwnDeletedStructure($user, $data->locationId) ?? (new IndustryStructureConfig())->setUser($user);
        $structure->setName($data->name);
        $structure->setSecurityType($data->securityType);
        $structure->setStructureType($data->structureType);
        $structure->setRigs($data->rigs);
        $structure->setIsDefault($data->isDefault);

        $cachedStructure = $data->locationId !== null && $data->locationId > 0
            ? $this->cachedStructureRepository->findByStructureId($data->locationId)
            : null;

        // The ESI cache knows where an imported structure stands; the submitted system is only a fallback.
        $solarSystemId = $cachedStructure?->getSolarSystemId() ?? $data->solarSystemId;
        if ($solarSystemId !== null && $solarSystemId > 0) {
            $structure->setSolarSystemId($solarSystemId);
        }

        if ($data->locationId !== null && $data->locationId > 0) {
            $structure->setLocationId($data->locationId);
            $corporationId = $user->getCorporationId();
            if ($corporationId !== null) {
                $structure->setCorporationId($corporationId);

                $structure->setIsCorporationStructure(
                    $cachedStructure !== null && $cachedStructure->getOwnerCorporationId() === $corporationId,
                );
            }
        }

        $this->entityManager->persist($structure);
        $this->entityManager->flush();

        return $this->mapper->structureToResource($structure);
    }

    /**
     * Re-importing a structure the user soft-deleted brings back their own row (same id)
     * instead of creating a duplicate; the submitted form values are then applied to it.
     */
    private function restoreOwnDeletedStructure(User $user, ?int $locationId): ?IndustryStructureConfig
    {
        if ($locationId === null || $locationId <= 0) {
            return null;
        }

        $structure = $this->structureConfigRepository->findDeletedByUserAndLocationId($user, $locationId);
        if ($structure === null) {
            return null;
        }

        $userId = $user->getId()?->toRfc4122();
        if ($userId === null) {
            throw new \LogicException('A user owning a stored structure must have an id.');
        }

        return $structure->unhideForUser($userId)->setIsDeleted(false);
    }
}
