<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\OreValueTableResource;
use App\Entity\User;
use App\Enum\MarketSide;
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
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OreValueTableResource
    {
        if (!$this->security->getUser() instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new BadRequestHttpException('No current request.');
        }

        $query = $request->query;
        $market = $this->marketOf($query);
        $side = $this->sideOf($query);
        $structureId = $this->structureIdOf($query, $market);
        $reprocessYield = $this->reprocessYieldOf($query);

        $resource = new OreValueTableResource();
        $resource->market = $market;
        $resource->side = $side->value;
        $resource->structureId = $structureId;
        $resource->reprocessYieldKnown = $reprocessYield !== null;
        $resource->rows = $this->oreValueTableService->build($market, $side, $structureId, $reprocessYield);

        return $resource;
    }

    /**
     * @param InputBag<string> $query
     */
    private function marketOf(InputBag $query): string
    {
        $market = $query->get('market');
        if (!in_array($market, self::MARKETS, true)) {
            throw new BadRequestHttpException(sprintf('market must be one of: %s.', implode(', ', self::MARKETS)));
        }

        return $market;
    }

    /**
     * @param InputBag<string> $query
     */
    private function sideOf(InputBag $query): MarketSide
    {
        $side = MarketSide::tryFrom((string) $query->get('side', MarketSide::Sell->value));
        if ($side === null) {
            throw new BadRequestHttpException('side must be buy or sell.');
        }

        return $side;
    }

    /**
     * @param InputBag<string> $query
     */
    private function structureIdOf(InputBag $query, string $market): ?int
    {
        if ($market !== OreValueTableService::MARKET_STRUCTURE) {
            return null;
        }

        $structureId = filter_var($query->get('structureId'), FILTER_VALIDATE_INT);
        if ($structureId === false || $structureId <= 0) {
            throw new BadRequestHttpException('structureId is required for the structure market.');
        }

        return $structureId;
    }

    /**
     * @param InputBag<string> $query
     */
    private function reprocessYieldOf(InputBag $query): ?float
    {
        if (!$query->has('reprocessYield')) {
            return null;
        }

        $reprocessYield = filter_var($query->get('reprocessYield'), FILTER_VALIDATE_FLOAT);
        if ($reprocessYield === false || $reprocessYield <= 0.0 || $reprocessYield > 1.0) {
            throw new BadRequestHttpException('reprocessYield must be greater than 0 and at most 1.');
        }

        return $reprocessYield;
    }
}
