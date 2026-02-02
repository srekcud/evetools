<?php

declare(strict_types=1);

namespace App\State\Provider\Ansiblex;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ansiblex\AnsiblexListResource;
use App\ApiResource\Ansiblex\AnsiblexLocationResource;
use App\ApiResource\Ansiblex\AnsiblexOwnerResource;
use App\ApiResource\Ansiblex\AnsiblexResource;
use App\Entity\AnsiblexJumpGate;
use App\Entity\User;
use App\Repository\AnsiblexJumpGateRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<AnsiblexListResource>
 */
class AnsiblexCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AnsiblexJumpGateRepository $ansiblexRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AnsiblexListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $mainCharacter = $user->getMainCharacter();
        $allianceId = $mainCharacter?->getAllianceId();

        // Filter by alliance if user is in one, otherwise show all active gates
        if ($allianceId) {
            $gates = $this->ansiblexRepository->findByAlliance($allianceId);
        } else {
            $gates = $this->ansiblexRepository->findAllActive();
        }

        $result = new AnsiblexListResource();
        $result->total = count($gates);
        $result->allianceId = $allianceId;
        $result->items = array_map(fn (AnsiblexJumpGate $gate) => $this->toResource($gate), $gates);

        return $result;
    }

    private function toResource(AnsiblexJumpGate $gate): AnsiblexResource
    {
        $resource = new AnsiblexResource();
        $resource->structureId = $gate->getStructureId();
        $resource->name = $gate->getName();

        $source = new AnsiblexLocationResource();
        $source->solarSystemId = $gate->getSourceSolarSystemId();
        $source->solarSystemName = $gate->getSourceSolarSystemName();
        $resource->source = $source;

        $destination = new AnsiblexLocationResource();
        $destination->solarSystemId = $gate->getDestinationSolarSystemId();
        $destination->solarSystemName = $gate->getDestinationSolarSystemName();
        $resource->destination = $destination;

        $owner = new AnsiblexOwnerResource();
        $owner->allianceId = $gate->getOwnerAllianceId();
        $owner->allianceName = $gate->getOwnerAllianceName();
        $resource->owner = $owner;

        $resource->isActive = $gate->isActive();
        $resource->lastSeenAt = $gate->getLastSeenAt()?->format('c');
        $resource->updatedAt = $gate->getUpdatedAt()->format('c');

        return $resource;
    }
}
