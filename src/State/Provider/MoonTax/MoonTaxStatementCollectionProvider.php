<?php

declare(strict_types=1);

namespace App\State\Provider\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MoonTax\MoonTaxStatementResource;
use App\Enum\MoonTaxStatus;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonTaxAllianceResolver;
use App\Service\MoonTax\MoonTaxManagerAccess;
use App\Service\MoonTax\MoonTaxStatementReader;
use App\Service\MoonTax\MoonTaxStatementView;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProviderInterface<MoonTaxStatementResource>
 */
final readonly class MoonTaxStatementCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MoonTaxManagerAccess $managerAccess,
        private MoonTaxStatementReader $statementReader,
        private MoonTaxAllianceResolver $nameResolver,
        private MoonTaxResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MoonTaxStatementResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $corporationId = (int) $uriVariables['corporationId'];
        $this->managerAccess->assertManages(MiningOperationRequest::userOf($this->security), $corporationId);

        $filters = $context['filters'] ?? [];
        $periodStart = $this->periodStartFilter($filters['period'] ?? null);
        $lateOnly = $this->lateFilter($filters['late'] ?? null);

        $views = array_values(array_filter(
            $this->statementReader->ofCorporation($corporationId),
            static fn (MoonTaxStatementView $view): bool => ($periodStart === null || $view->periodStart->format('Y-m-d') === $periodStart)
                && (!$lateOnly || $view->status === MoonTaxStatus::Late),
        ));

        $names = $this->nameResolver->characterNames(array_values(array_unique(array_map(static fn (MoonTaxStatementView $view): int => $view->characterId, $views))));

        $recordedCorporationIds = array_values(array_unique(array_filter(array_map(static fn (MoonTaxStatementView $view): ?int => $view->recordedCorporationId, $views))));
        $affiliations = $this->nameResolver->affiliations($recordedCorporationIds);

        return array_map(
            fn (MoonTaxStatementView $view): MoonTaxStatementResource => $this->mapper->statementToResource(
                $view,
                $names[$view->characterId],
                $view->recordedCorporationId === null ? null : $affiliations[$view->recordedCorporationId],
            ),
            $views,
        );
    }

    private function periodStartFilter(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('The period filter must be a date formatted as YYYY-MM-DD');
        }

        return $value;
    }

    private function lateFilter(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        $late = is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($late === null) {
            throw new BadRequestHttpException('The late filter must be true or false');
        }

        return $late;
    }
}
