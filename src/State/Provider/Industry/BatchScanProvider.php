<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\BatchScanResultResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\BatchProfitScannerService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<BatchScanResultResource>
 */
class BatchScanProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly BatchProfitScannerService $scannerService,
        private readonly IndustryUserSettingsRepository $settingsRepository,
    ) {
    }

    /**
     * @return list<BatchScanResultResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();

        // Parse query params
        $category = $request?->query->get('category', 'all') ?? 'all';
        $minMarginParam = $request?->query->get('minMargin');
        $minDailyVolumeParam = $request?->query->get('minDailyVolume');
        $sellVenue = $request?->query->get('sellVenue', 'jita') ?? 'jita';
        $structureIdParam = $request?->query->get('structureId');
        $solarSystemIdParam = $request?->query->get('solarSystemId');

        $minMarginPercent = $minMarginParam !== null ? (float) $minMarginParam : null;
        $minDailyVolume = $minDailyVolumeParam !== null ? (float) $minDailyVolumeParam : null;

        // Load user settings for defaults
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);

        $brokerFeeRate = $settings?->getBrokerFeeRate() ?? EveConstants::DEFAULT_BROKER_FEE_RATE;
        $salesTaxRate = $settings?->getSalesTaxRate() ?? EveConstants::DEFAULT_SALES_TAX_RATE;
        $exportCostPerM3 = $settings?->getExportCostPerM3() ?? EveConstants::DEFAULT_EXPORT_COST_PER_M3;

        // Resolve structure ID
        $structureId = $structureIdParam !== null ? (int) $structureIdParam : null;
        if ($structureId === null && $sellVenue === 'structure') {
            $structureId = $user->getPreferredMarketStructureId();
        }

        // Resolve solar system ID
        $solarSystemId = $solarSystemIdParam !== null
            ? (int) $solarSystemIdParam
            : ($settings?->getFavoriteManufacturingSystemId() ?? EveConstants::PERIMETER_SOLAR_SYSTEM_ID);

        $results = $this->scannerService->scan(
            $category,
            $minMarginPercent,
            $minDailyVolume,
            $sellVenue,
            $structureId,
            $solarSystemId,
            $brokerFeeRate,
            $salesTaxRate,
            $exportCostPerM3,
            $user,
        );

        return array_map([$this, 'mapToResource'], $results);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapToResource(array $data): BatchScanResultResource
    {
        $resource = new BatchScanResultResource();
        $resource->typeId = $data['typeId'];
        $resource->typeName = $data['typeName'];
        $resource->groupName = $data['groupName'];
        $resource->categoryLabel = $data['categoryLabel'];
        $resource->marginPercent = $data['marginPercent'];
        $resource->profitPerUnit = $data['profitPerUnit'];
        $resource->dailyVolume = $data['dailyVolume'];
        $resource->iskPerDay = $data['iskPerDay'];
        $resource->materialCost = $data['materialCost'];
        $resource->importCost = $data['importCost'];
        $resource->exportCost = $data['exportCost'];
        $resource->sellPrice = $data['sellPrice'];
        $resource->meUsed = $data['meUsed'];
        $resource->activityType = $data['activityType'];
        $resource->isFactionBlueprint = $data['isFactionBlueprint'];
        $resource->bpcCostPerRun = $data['bpcCostPerRun'];
        $resource->hasAllSkills = $data['hasAllSkills'];
        $resource->missingSkillCount = $data['missingSkillCount'];

        return $resource;
    }
}
