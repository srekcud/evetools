<?php

declare(strict_types=1);

namespace App\State\Processor\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Market\CreateAlertInput;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertPriceSource;
use App\Repository\Sde\InvTypeRepository;
use App\Service\MarketAlertPriceResolver;
use App\Service\MarketAlertRules;
use App\State\Provider\Market\MarketAlertResourceMapper;
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
        private readonly MarketAlertPriceResolver $priceResolver,
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
        $direction = MarketAlertRules::directionOrBadRequest($data->direction);

        // Validate price source
        $priceSource = AlertPriceSource::tryFrom($data->priceSource);
        if ($priceSource === null) {
            throw new BadRequestHttpException('Invalid price source. Must be one of: jita_sell, jita_buy, structure_sell, structure_buy');
        }

        $structureId = $this->resolveStructureId($user, $priceSource, $data->structureId);

        // Validate threshold
        MarketAlertRules::assertThresholdIsPositive($data->threshold);

        $alert = new MarketPriceAlert();
        $alert->setUser($user);
        $alert->setTypeId($data->typeId);
        $alert->setTypeName($invType->getTypeName());
        $alert->setDirection($direction);
        $alert->setThreshold($data->threshold);
        $alert->setPriceSource($priceSource);
        $alert->setStructureId($structureId);

        $this->em->persist($alert);
        $this->em->flush();

        return MarketAlertResourceMapper::toResource(
            $alert,
            $this->priceResolver->currentPrice($user, $alert),
            MarketAlertResourceMapper::structureNameOf($user, $alert),
        );
    }

    private function resolveStructureId(User $user, AlertPriceSource $priceSource, ?int $requestedStructureId): ?int
    {
        $isStructureSource = in_array($priceSource, [AlertPriceSource::StructureSell, AlertPriceSource::StructureBuy], true);

        if (!$isStructureSource) {
            if ($requestedStructureId !== null) {
                throw new BadRequestHttpException('A structure can only be set on a structure price source');
            }

            return null;
        }

        $effectiveStructureId = $user->getEffectiveMarketStructureId();

        if ($requestedStructureId === null) {
            if ($effectiveStructureId === null) {
                throw new BadRequestHttpException('You must set a preferred market structure before using structure price alerts');
            }

            return $effectiveStructureId;
        }

        if (!$user->followsMarketStructure($requestedStructureId)) {
            throw new BadRequestHttpException('The structure must be your preferred or a followed market structure');
        }

        return $requestedStructureId;
    }
}
