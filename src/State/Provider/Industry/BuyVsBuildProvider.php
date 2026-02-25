<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\BuyVsBuildResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\BuyVsBuildService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<BuyVsBuildResource>
 */
class BuyVsBuildProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly BuyVsBuildService $buyVsBuildService,
        private readonly IndustryUserSettingsRepository $settingsRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BuyVsBuildResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $typeId = (int) $uriVariables['typeId'];

        // Parse query params with defaults
        $runs = max(1, (int) ($request?->query->get('runs', '1') ?? '1'));
        $me = (int) ($request?->query->get('me', '10') ?? '10');

        $solarSystemIdParam = $request?->query->get('solarSystemId');
        $structureIdParam = $request?->query->get('structureId');

        // Load user settings
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);

        $brokerFeeRate = $settings?->getBrokerFeeRate() ?? EveConstants::DEFAULT_BROKER_FEE_RATE;
        $salesTaxRate = $settings?->getSalesTaxRate() ?? EveConstants::DEFAULT_SALES_TAX_RATE;

        // Resolve structure ID
        $structureId = $structureIdParam !== null ? (int) $structureIdParam : null;
        if ($structureId === null) {
            $structureId = $user->getPreferredMarketStructureId();
        }
        if ($structureId === null) {
            $structureId = EveConstants::JITA_STATION_ID;
        }

        // Resolve solar system ID
        $solarSystemId = $solarSystemIdParam !== null
            ? (int) $solarSystemIdParam
            : ($settings?->getFavoriteManufacturingSystemId() ?? EveConstants::PERIMETER_SOLAR_SYSTEM_ID);

        $result = $this->buyVsBuildService->analyze(
            $typeId,
            $runs,
            $me,
            $solarSystemId,
            $structureId,
            $brokerFeeRate,
            $salesTaxRate,
            $user,
        );

        return $this->mapToResource($result);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapToResource(array $data): BuyVsBuildResource
    {
        $resource = new BuyVsBuildResource();
        $resource->typeId = $data['typeId'];
        $resource->typeName = $data['typeName'];
        $resource->isT2 = $data['isT2'];
        $resource->runs = $data['runs'];
        $resource->totalProductionCost = $data['totalProductionCost'];
        $resource->sellPrice = $data['sellPrice'];
        $resource->marginPercent = $data['marginPercent'];
        $resource->components = $data['components'];
        $resource->buildAllCost = $data['buildAllCost'];
        $resource->buyAllCost = $data['buyAllCost'];
        $resource->optimalMixCost = $data['optimalMixCost'];
        $resource->buildTypeIds = $data['buildTypeIds'];
        $resource->buyTypeIds = $data['buyTypeIds'];

        return $resource;
    }
}
