<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProfitMarginResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Industry\Application\Decryptors;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\AnalysisSolarSystems;
use App\Service\Industry\ProfitMarginService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProfitMarginResource>
 */
class ProfitMarginProvider implements ProviderInterface
{

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly ProfitMarginService $profitMarginService,
        private readonly IndustryUserSettingsRepository $settingsRepository,
        private readonly Decryptors $decryptors,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProfitMarginResource
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
        $te = (int) ($request?->query->get('te', '20') ?? '20');

        $solarSystemIdParam = $request?->query->get('solarSystemId');
        $structureIdParam = $request?->query->get('structureId');
        $decryptorTypeIdParam = $request?->query->get('decryptorTypeId');

        // Load user settings
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);

        $brokerFeeRate = $settings?->getBrokerFeeRate() ?? EveConstants::DEFAULT_BROKER_FEE_RATE;
        $salesTaxRate = $settings?->getSalesTaxRate() ?? EveConstants::DEFAULT_SALES_TAX_RATE;

        // Resolve structure ID: query param > user's preferred market structure
        $structureId = $structureIdParam !== null ? (int) $structureIdParam : null;
        if ($structureId === null) {
            $structureId = $user->getPreferredMarketStructureId();
        }
        // Fallback: Jita station ID if no structure configured
        if ($structureId === null) {
            $structureId = EveConstants::JITA_STATION_ID;
        }

        $solarSystems = AnalysisSolarSystems::resolve($solarSystemIdParam !== null ? (int) $solarSystemIdParam : null, $settings);

        // Decryptor
        $decryptorTypeId = $decryptorTypeIdParam !== null ? (int) $decryptorTypeIdParam : null;
        if ($decryptorTypeId !== null && !$this->isDecryptor($decryptorTypeId)) {
            throw new BadRequestHttpException(sprintf('Type %d is not a decryptor.', $decryptorTypeId));
        }

        $result = $this->profitMarginService->analyze(
            $typeId,
            $runs,
            $me,
            $te,
            $structureId,
            $solarSystems->manufacturingSolarSystemId,
            $decryptorTypeId,
            $brokerFeeRate,
            $salesTaxRate,
            $user,
            $solarSystems->reactionSolarSystemId,
        );

        return $this->mapToResource($result);
    }

    private function isDecryptor(int $typeId): bool
    {
        foreach ($this->decryptors->all() as $decryptor) {
            if ($decryptor->typeId === $typeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapToResource(array $data): ProfitMarginResource
    {
        $resource = new ProfitMarginResource();
        $resource->typeId = $data['typeId'];
        $resource->typeName = $data['typeName'];
        $resource->isT2 = $data['isT2'];
        $resource->runs = $data['runs'];
        $resource->outputQuantity = $data['outputQuantity'];
        $resource->outputPerRun = $data['outputPerRun'];
        $resource->materialCost = $data['materialCost'];
        $resource->materials = $data['materials'];
        $resource->jobInstallCost = $data['jobInstallCost'];
        $resource->jobInstallSteps = $data['jobInstallSteps'];
        $resource->inventionCost = $data['inventionCost'];
        $resource->totalCost = $data['totalCost'];
        $resource->costPerUnit = $data['costPerUnit'];
        $resource->invention = $data['invention'];
        $resource->sellPrices = $data['sellPrices'];
        $resource->brokerFeeRate = $data['brokerFeeRate'];
        $resource->salesTaxRate = $data['salesTaxRate'];
        $resource->margins = $data['margins'];
        $resource->dailyVolume = $data['dailyVolume'];
        $resource->missingData = $data['missingData'];

        return $resource;
    }
}
