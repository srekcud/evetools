<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\PivotAdvisorResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\PivotAdvisorService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PivotAdvisorResource>
 */
class PivotAdvisorProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly PivotAdvisorService $pivotAdvisorService,
        private readonly IndustryUserSettingsRepository $settingsRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PivotAdvisorResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $typeId = (int) $uriVariables['typeId'];

        // Parse query params with defaults
        $runs = max(1, (int) ($request?->query->get('runs', '1') ?? '1'));

        $solarSystemIdParam = $request?->query->get('solarSystemId');

        // Load user settings
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);

        $brokerFeeRate = $settings?->getBrokerFeeRate() ?? EveConstants::DEFAULT_BROKER_FEE_RATE;
        $salesTaxRate = $settings?->getSalesTaxRate() ?? EveConstants::DEFAULT_SALES_TAX_RATE;

        // Resolve solar system ID
        $solarSystemId = $solarSystemIdParam !== null
            ? (int) $solarSystemIdParam
            : ($settings?->getFavoriteManufacturingSystemId() ?? EveConstants::PERIMETER_SOLAR_SYSTEM_ID);

        $result = $this->pivotAdvisorService->analyze(
            $typeId,
            $runs,
            $solarSystemId,
            $brokerFeeRate,
            $salesTaxRate,
            $user,
        );

        return $this->mapToResource($result);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapToResource(array $data): PivotAdvisorResource
    {
        $resource = new PivotAdvisorResource();
        $resource->typeId = $data['typeId'];
        $resource->sourceProduct = $data['sourceProduct'];
        $resource->matrix = $data['matrix'];
        $resource->candidates = $data['candidates'];
        $resource->matrixProductIds = $data['matrixProductIds'];

        return $resource;
    }
}
