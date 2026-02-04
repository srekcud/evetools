<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\MiningEntryResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MiningEntryResource>
 */
class MiningEntryProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MiningEntryRepository $miningEntryRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningEntryResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null) {
            throw new NotFoundHttpException('Mining entry not found');
        }

        $entry = $this->miningEntryRepository->find($id);

        if ($entry === null || $entry->getUser()->getId()?->toRfc4122() !== $user->getId()?->toRfc4122()) {
            throw new NotFoundHttpException('Mining entry not found');
        }

        return $this->toResource($entry);
    }

    private function toResource(MiningEntry $entry): MiningEntryResource
    {
        $resource = new MiningEntryResource();
        $resource->id = $entry->getId()?->toRfc4122() ?? '';
        $resource->characterId = $entry->getCharacterId();
        $resource->characterName = $entry->getCharacterName();
        $resource->date = $entry->getDate()->format('Y-m-d');
        $resource->typeId = $entry->getTypeId();
        $resource->typeName = $entry->getTypeName();
        $resource->solarSystemId = $entry->getSolarSystemId();
        $resource->solarSystemName = $entry->getSolarSystemName();
        $resource->quantity = $entry->getQuantity();
        $resource->unitPrice = $entry->getUnitPrice();
        $resource->totalValue = $entry->getTotalValue();
        $resource->usage = $entry->getUsage();
        $resource->linkedProjectId = $entry->getLinkedProjectId();
        $resource->linkedCorpProjectId = $entry->getLinkedCorpProjectId();
        $resource->syncedAt = $entry->getSyncedAt()->format('c');

        return $resource;
    }
}
