<?php

declare(strict_types=1);

namespace App\State\Processor\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Market\CreateAlertInput;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
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
        $validDirections = [MarketPriceAlert::DIRECTION_ABOVE, MarketPriceAlert::DIRECTION_BELOW];
        if (!in_array($data->direction, $validDirections, true)) {
            throw new BadRequestHttpException('Invalid direction. Must be "above" or "below"');
        }

        // Validate price source
        $validSources = [MarketPriceAlert::SOURCE_JITA_SELL, MarketPriceAlert::SOURCE_JITA_BUY];
        if (!in_array($data->priceSource, $validSources, true)) {
            throw new BadRequestHttpException('Invalid price source. Must be "jita_sell" or "jita_buy"');
        }

        // Validate threshold
        if ($data->threshold <= 0) {
            throw new BadRequestHttpException('Threshold must be positive');
        }

        $alert = new MarketPriceAlert();
        $alert->setUser($user);
        $alert->setTypeId($data->typeId);
        $alert->setTypeName($invType->getTypeName());
        $alert->setDirection($data->direction);
        $alert->setThreshold($data->threshold);
        $alert->setPriceSource($data->priceSource);

        $this->em->persist($alert);
        $this->em->flush();

        // Get current price for response
        $currentPrice = $data->priceSource === MarketPriceAlert::SOURCE_JITA_SELL
            ? $this->jitaMarketService->getPrice($data->typeId)
            : $this->jitaMarketService->getBuyPrice($data->typeId);

        $resource = new MarketAlertResource();
        $resource->id = $alert->getId()?->toRfc4122() ?? '';
        $resource->typeId = $alert->getTypeId();
        $resource->typeName = $alert->getTypeName();
        $resource->direction = $alert->getDirection();
        $resource->threshold = $alert->getThreshold();
        $resource->priceSource = $alert->getPriceSource();
        $resource->status = $alert->getStatus();
        $resource->currentPrice = $currentPrice;
        $resource->triggeredAt = null;
        $resource->createdAt = $alert->getCreatedAt()->format('c');

        return $resource;
    }
}
