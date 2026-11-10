<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\OreValueTableResource;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Repository\ReprocessingProfileRepository;
use App\Service\MiningValuation\MiningValuationProfileReader;
use App\Service\MiningValuation\MiningValuationSettings;
use App\Service\OreValueTableService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<OreValueTableResource>
 */
final readonly class OreValueTableProvider implements ProviderInterface
{
    private const array MARKETS = [OreValueTableService::MARKET_JITA, OreValueTableService::MARKET_STRUCTURE];

    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private OreValueTableService $oreValueTableService,
        private MiningValuationProfileReader $profileReader,
        private ReprocessingProfileRepository $reprocessingProfileRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OreValueTableResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new BadRequestHttpException('No current request.');
        }

        $query = $request->query;
        $settings = $this->profileReader->read($user);
        $market = $this->marketOf($query, $settings);
        $side = $this->sideOf($query, $settings);
        $structureId = $this->structureIdOf($query, $market, $settings);
        $reprocessYield = $this->reprocessYieldOf($query, $user);
        $exportTax = $this->exportTaxOf($query, $settings);

        $resource = new OreValueTableResource();
        $resource->market = $market;
        $resource->side = $side->value;
        $resource->structureId = $structureId;
        $resource->reprocessYieldKnown = $reprocessYield !== null;
        $resource->valuationSource = [
            'market' => $market,
            'side' => $side->value,
            'form' => $settings->form->value,
            'structureName' => $structureId !== null && $structureId === $settings->structureId ? $settings->structureName : null,
        ];
        $resource->rows = $this->oreValueTableService->build($market, $side, $structureId, $reprocessYield, $exportTax);

        return $resource;
    }

    /**
     * @param InputBag<string> $query
     */
    private function marketOf(InputBag $query, MiningValuationSettings $settings): string
    {
        $market = $query->get('market', $settings->market->value);
        if (!in_array($market, self::MARKETS, true)) {
            throw new BadRequestHttpException(sprintf('market must be one of: %s.', implode(', ', self::MARKETS)));
        }

        return $market;
    }

    /**
     * @param InputBag<string> $query
     */
    private function sideOf(InputBag $query, MiningValuationSettings $settings): MarketSide
    {
        $side = MarketSide::tryFrom((string) $query->get('side', $settings->side->value));
        if ($side === null) {
            throw new BadRequestHttpException('side must be buy or sell.');
        }

        return $side;
    }

    /**
     * @param InputBag<string> $query
     */
    private function structureIdOf(InputBag $query, string $market, MiningValuationSettings $settings): ?int
    {
        if ($market !== OreValueTableService::MARKET_STRUCTURE) {
            return null;
        }

        $structureId = filter_var($query->get('structureId', $settings->structureId), FILTER_VALIDATE_INT);
        if ($structureId === false || $structureId <= 0) {
            throw new BadRequestHttpException('structureId is required for the structure market.');
        }

        return $structureId;
    }

    /**
     * @param InputBag<string> $query
     */
    private function reprocessYieldOf(InputBag $query, User $user): ?float
    {
        if (!$query->has('reprocessYield')) {
            return $this->reprocessingProfileRepository->findOneBy(['user' => $user])?->getReprocessYield();
        }

        $reprocessYield = filter_var($query->get('reprocessYield'), FILTER_VALIDATE_FLOAT);
        if ($reprocessYield === false || $reprocessYield <= 0.0 || $reprocessYield > 1.0) {
            throw new BadRequestHttpException('reprocessYield must be greater than 0 and at most 1.');
        }

        return $reprocessYield;
    }

    /**
     * @param InputBag<string> $query
     */
    private function exportTaxOf(InputBag $query, MiningValuationSettings $settings): float
    {
        if (!$query->has('exportTax')) {
            return $settings->exportTax;
        }

        $exportTax = filter_var($query->get('exportTax'), FILTER_VALIDATE_FLOAT);
        if ($exportTax === false || $exportTax < 0.0) {
            throw new BadRequestHttpException('exportTax must be a number greater than or equal to 0.');
        }

        return $exportTax;
    }
}
