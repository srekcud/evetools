<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\LedgerIndustryResource;
use App\Entity\User;
use App\Service\Industry\Revenue\IndustryRevenueMonth;
use App\Service\Industry\Revenue\IndustryRevenueProduct;
use App\Service\Industry\Revenue\IndustryRevenueReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<LedgerIndustryResource>
 */
class LedgerIndustryProvider implements ProviderInterface
{
    private const int DEFAULT_DAYS = 30;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly IndustryRevenueReader $industryRevenueReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LedgerIndustryResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $days = (int) ($this->requestStack->getCurrentRequest()?->query->get('days', self::DEFAULT_DAYS) ?? self::DEFAULT_DAYS);
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");
        $report = $this->industryRevenueReader->reportFor($user, $from, $to);

        $resource = new LedgerIndustryResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->totals = [
            'profit' => $report->totals->profit,
            'projectCount' => $report->totals->projectCount,
            'unknownProjectCount' => $report->totals->unknownProjectCount,
            'capital' => $report->totals->capital,
            'slotHours' => $report->totals->slotHours,
            'iskPerHour' => $report->totals->iskPerHour,
        ];
        $resource->months = array_map(static fn (IndustryRevenueMonth $month): array => [
            'month' => $month->month,
            'projectCount' => $month->projectCount,
            'unknownProjectCount' => $month->unknownProjectCount,
            'profit' => $month->profit,
            'capital' => $month->capital,
            'marginPercent' => $month->marginPercent,
            'slotHours' => $month->slotHours,
            'iskPerHour' => $month->iskPerHour,
        ], $report->months);
        $resource->products = array_map(static fn (IndustryRevenueProduct $product): array => [
            'typeId' => $product->typeId,
            'typeName' => $product->typeName,
            'projectCount' => $product->projectCount,
            'unknownProjectCount' => $product->unknownProjectCount,
            'profit' => $product->profit,
        ], $report->products);

        return $resource;
    }
}
