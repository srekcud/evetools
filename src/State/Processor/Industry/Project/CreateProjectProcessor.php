<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\ApiResource\Input\Industry\Project\CreateProjectInput;
use App\ApiResource\Input\Industry\Project\ProjectFees;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectTemplateRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Project\CreateProjectData;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectSellStructures;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateProjectInput, IndustryProjectResource>
 */
class CreateProjectProcessor implements ProcessorInterface
{
    private const float PERCENT_PER_RATE = 100.0;
    private const int FLOAT_NOISE_DECIMALS = 10;

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectService $projectService,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly ProjectResourceMapper $mapper,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly SellerSalesFeesResolver $salesFeesResolver,
        private readonly IndustryProjectTemplateRepository $templateRepository,
        private readonly ProjectSellStructures $sellStructures,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IndustryProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateProjectInput);

        if (empty($data->items)) {
            throw new BadRequestHttpException('At least one item is required');
        }

        // Resolve items with missing typeId (from bulk paste) by looking up the name in SDE
        $data->items = $this->resolveItemTypeIds($data->items);

        $resolvedGroupIds = array_values(array_unique([
            ...IndustryBlacklistService::resolveGroupIds($data->blacklistGroupIds, $data->blacklistCategoryKeys),
            ...$user->getIndustryBlacklistGroupIds(),
        ]));
        $blacklistTypeIds = array_values(array_unique([
            ...$data->blacklistTypeIds,
            ...$user->getIndustryBlacklistTypeIds(),
        ]));

        $sellerFees = $this->salesFeesResolver->forUser($user);
        $brokerFeePercent = $data->brokerFeePercent ?? (null === $sellerFees ? null : self::percentOf($sellerFees->brokerFeeRate));
        $salesTaxPercent = $data->salesTaxPercent ?? (null === $sellerFees ? null : self::percentOf($sellerFees->salesTaxRate));
        if ($brokerFeePercent !== null && $salesTaxPercent !== null) {
            ProjectFees::rejectEffectiveFeesAboveMaximum($brokerFeePercent, $salesTaxPercent);
        }

        $template = $this->resolveVisibleTemplate($user, $data->templateId);

        try {
            $projectData = new CreateProjectData(
                name: $data->name ?? '',
                items: $data->items,
                blacklistGroupIds: $resolvedGroupIds,
                blacklistTypeIds: $blacklistTypeIds,
                containerName: $data->containerName,
                lineRentalRatesOverride: $data->lineRentalRatesOverride,
                brokerFeePercent: $brokerFeePercent,
                salesTaxPercent: $salesTaxPercent,
                private: $data->private,
                personalUse: $data->personalUse,
                acceptsOutsideFavoriteSystem: $data->acceptsOutsideFavoriteSystem,
                minimumOutsideSaving: $data->minimumOutsideSaving,
                template: $template,
                excludesInvention: $data->excludesInvention ?? $template?->isExcludesInvention() ?? $user->isIndustryExcludeInvention(),
                sellStructure: null === $data->sellStructureId ? $this->sellStructures->defaultFor($user) : $this->sellStructures->choose($user, $data->sellStructureId),
            );

            $project = $this->projectService->createProject($user, $projectData);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // Find the owner membership to pass to the mapper
        $ownerMembership = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'role' => ParticipantRole::Owner,
        ]);

        return $this->mapper->projectToResource($project, $ownerMembership);
    }

    private function resolveVisibleTemplate(User $user, ?string $templateId): ?IndustryProjectTemplate
    {
        if ($templateId === null) {
            return null;
        }

        foreach ($this->templateRepository->findVisibleTo($user) as $template) {
            if ((string) $template->getId() === $templateId) {
                return $template;
            }
        }

        throw new NotFoundHttpException('Template not found');
    }

    private static function percentOf(float $rate): float
    {
        return round($rate * self::PERCENT_PER_RATE, self::FLOAT_NOISE_DECIMALS);
    }

    /**
     * Resolve items where typeId is 0 (bulk paste) by looking up typeName in SDE.
     * Also validates that each resolved type has a manufacturing blueprint or reaction.
     *
     * @param list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> $items
     * @return list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}>
     */
    private function resolveItemTypeIds(array $items): array
    {
        $unresolvedNames = [];

        foreach ($items as $index => $item) {
            if ($item['typeId'] === 0 && !empty($item['typeName'])) {
                $type = $this->invTypeRepository->findOneByName(trim($item['typeName']));

                if ($type === null) {
                    $unresolvedNames[] = $item['typeName'];
                    continue;
                }

                $items[$index]['typeId'] = $type->getTypeId();
                $items[$index]['typeName'] = $type->getTypeName();
            }
        }

        if (!empty($unresolvedNames)) {
            throw new BadRequestHttpException(sprintf(
                'Unknown item(s): %s. Check the spelling and try again.',
                implode(', ', $unresolvedNames),
            ));
        }

        // Validate that all items have a blueprint or reaction
        $noBlueprintNames = [];

        foreach ($items as $item) {
            $product = $this->activityProductRepository->findBlueprintForProduct($item['typeId'], 1)
                ?? $this->activityProductRepository->findBlueprintForProduct($item['typeId'], 11);

            if ($product === null) {
                $noBlueprintNames[] = $item['typeName'];
            }
        }

        if (!empty($noBlueprintNames)) {
            throw new BadRequestHttpException(sprintf(
                'No blueprint or reaction found for: %s. These items cannot be manufactured.',
                implode(', ', $noBlueprintNames),
            ));
        }

        return $items;
    }
}
