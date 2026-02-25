<?php

declare(strict_types=1);

namespace App\State\Processor\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Market\CreateAlertInput;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateAlertInput, MarketAlertResource>
 */
class CreateAlertProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MarketAlertResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateAlertInput);

        // Validate type
        $invType = $this->invTypeRepository->findByTypeId($data->typeId);
        if ($invType === null) {
            throw new BadRequestHttpException('Invalid type ID');
        }

        // Validate direction
        $direction = AlertDirection::tryFrom($data->direction);
        if ($direction === null) {
            throw new BadRequestHttpException('Invalid direction. Must be "above" or "below"');
        }

        // Validate price source
        $priceSource = AlertPriceSource::tryFrom($data->priceSource);
        if ($priceSource === null) {
            throw new BadRequestHttpException('Invalid price source. Must be one of: jita_sell, jita_buy, structure_sell, structure_buy');
        }

        // Structure sources require a preferred structure
        if (in_array($priceSource, [AlertPriceSource::StructureSell, AlertPriceSource::StructureBuy], true)
            && $user->getPreferredMarketStructureId() === null
        ) {
            throw new BadRequestHttpException('You must set a preferred market structure before using structure price alerts');
        }

        // Validate threshold
        if ($data->threshold <= 0) {
            throw new BadRequestHttpException('Threshold must be positive');
        }

        $alert = new MarketPriceAlert();
        $alert->setUser($user);
        $alert->setTypeId($data->typeId);
        $alert->setTypeName($invType->getTypeName());
        $alert->setDirection($direction);
        $alert->setThreshold($data->threshold);
        $alert->setPriceSource($priceSource);

        $this->em->persist($alert);
        $this->em->flush();

        // Get current price for response
        $currentPrice = match ($priceSource) {
            AlertPriceSource::JitaSell => $this->jitaMarketService->getPricesWithFallback([$data->typeId])[$data->typeId] ?? null,
            AlertPriceSource::JitaBuy => $this->jitaMarketService->getBuyPricesWithFallback([$data->typeId])[$data->typeId] ?? null,
            AlertPriceSource::StructureSell => $this->structureMarketService->getLowestSellPrice(
                $user->getPreferredMarketStructureId(),
                $data->typeId,
            ),
            AlertPriceSource::StructureBuy => $this->structureMarketService->getHighestBuyPrice(
                $user->getPreferredMarketStructureId(),
                $data->typeId,
            ),
        };

        $resource = new MarketAlertResource();
        $resource->id = $alert->getId()?->toRfc4122() ?? '';
        $resource->typeId = $alert->getTypeId();
        $resource->typeName = $alert->getTypeName();
        $resource->direction = $alert->getDirection()->value;
        $resource->threshold = $alert->getThreshold();
        $resource->priceSource = $alert->getPriceSource()->value;
        $resource->status = $alert->getStatus()->value;
        $resource->currentPrice = $currentPrice;
        $resource->triggeredAt = null;
        $resource->createdAt = $alert->getCreatedAt()->format('c');

        return $resource;
    }
}
