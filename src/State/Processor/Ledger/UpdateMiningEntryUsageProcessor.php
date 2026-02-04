<?php

declare(strict_types=1);

namespace App\State\Processor\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Ledger\MiningEntryResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<MiningEntryResource, MiningEntryResource>
 */
class UpdateMiningEntryUsageProcessor implements ProcessorInterface
{
    private const VALID_USAGES = [
        MiningEntry::USAGE_UNKNOWN,
        MiningEntry::USAGE_SOLD,
        MiningEntry::USAGE_CORP_PROJECT,
        MiningEntry::USAGE_INDUSTRY,
    ];

    public function __construct(
        private readonly Security $security,
        private readonly MiningEntryRepository $miningEntryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningEntryResource
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

        // Update usage if provided
        if ($data instanceof MiningEntryResource && isset($data->usage)) {
            if (!in_array($data->usage, self::VALID_USAGES, true)) {
                throw new BadRequestHttpException(sprintf(
                    'Invalid usage value. Must be one of: %s',
                    implode(', ', self::VALID_USAGES)
                ));
            }
            $entry->setUsage($data->usage);
        }

        // Update linked project ID if provided
        if ($data instanceof MiningEntryResource && property_exists($data, 'linkedProjectId')) {
            $entry->setLinkedProjectId($data->linkedProjectId);
        }

        // Update linked corp project ID if provided
        if ($data instanceof MiningEntryResource && property_exists($data, 'linkedCorpProjectId')) {
            $entry->setLinkedCorpProjectId($data->linkedCorpProjectId);
        }

        $this->entityManager->flush();

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
