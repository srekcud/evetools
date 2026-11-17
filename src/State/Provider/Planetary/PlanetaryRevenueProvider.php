<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryRevenueResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryRevenueCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryRevenueResource>
 */
final readonly class PlanetaryRevenueProvider implements ProviderInterface
{
    private const DEFAULT_DAYS = 30;
    private const MIN_DAYS = 1;
    private const MAX_DAYS = 365;

    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private PlanetaryRevenueCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryRevenueResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $days = $this->requestedDays();
        $report = $this->calculator->calculate($user, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $days);

        $resource = new PlanetaryRevenueResource();
        $resource->periodEnd = $report['periodEnd'];
        $resource->days = $report['days'];
        $resource->tiers = $report['tiers'];
        $resource->realisedIsk = $report['realisedIsk'];
        $resource->realisedChange = $report['realisedChange'];
        $resource->realisedDaily = $report['realisedDaily'];
        $resource->realisedContributors = $report['realisedContributors'];
        $resource->estimate = $report['estimate'];
        $resource->toFix = $report['toFix'];

        return $resource;
    }

    private function requestedDays(): int
    {
        $requested = $this->requestStack->getCurrentRequest()?->query->get('days');
        if ($requested === null) {
            return self::DEFAULT_DAYS;
        }

        $days = filter_var($requested, FILTER_VALIDATE_INT);
        if ($days === false || $days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new BadRequestHttpException(sprintf('days must be an integer between %d and %d', self::MIN_DAYS, self::MAX_DAYS));
        }

        return $days;
    }
}
