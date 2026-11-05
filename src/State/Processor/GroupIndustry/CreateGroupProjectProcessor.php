<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\GroupIndustry\CreateGroupProjectInput;
use App\ApiResource\Input\GroupIndustry\GroupProjectFees;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateGroupProjectInput, GroupIndustryProjectResource>
 */
class CreateGroupProjectProcessor implements ProcessorInterface
{
    private const float PERCENT_PER_RATE = 100.0;
    private const int FLOAT_NOISE_DECIMALS = 10;

    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectService $projectService,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustryResourceMapper $mapper,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly SellerSalesFeesResolver $salesFeesResolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustryProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateGroupProjectInput);

        if (empty($data->items)) {
            throw new BadRequestHttpException('At least one item is required');
        }

        // Resolve items with missing typeId (from bulk paste) by looking up the name in SDE
        $data->items = $this->resolveItemTypeIds($data->items);

        // Resolve category keys to SDE group IDs
        $resolvedGroupIds = $data->blacklistGroupIds;
        foreach (IndustryBlacklistService::BLACKLIST_CATEGORIES as $category) {
            if (in_array($category['key'], $data->blacklistCategoryKeys, true)) {
                $resolvedGroupIds = array_merge($resolvedGroupIds, $category['groupIds']);
            }
        }
        $resolvedGroupIds = array_values(array_unique($resolvedGroupIds));

        $sellerFees = $this->salesFeesResolver->forUser($user);
        $brokerFeePercent = $data->brokerFeePercent ?? (null === $sellerFees ? null : self::percentOf($sellerFees->brokerFeeRate));
        $salesTaxPercent = $data->salesTaxPercent ?? (null === $sellerFees ? null : self::percentOf($sellerFees->salesTaxRate));
        if ($brokerFeePercent !== null && $salesTaxPercent !== null) {
            GroupProjectFees::rejectEffectiveFeesAboveMaximum($brokerFeePercent, $salesTaxPercent);
        }

        try {
            $projectData = new CreateProjectData(
                name: $data->name ?? '',
                items: $data->items,
                blacklistGroupIds: $resolvedGroupIds,
                blacklistTypeIds: $data->blacklistTypeIds,
                containerName: $data->containerName,
                lineRentalRatesOverride: $data->lineRentalRatesOverride,
                brokerFeePercent: $brokerFeePercent,
                salesTaxPercent: $salesTaxPercent,
            );

            $project = $this->projectService->createProject($user, $projectData);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // Find the owner membership to pass to the mapper
        $ownerMembership = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'role' => GroupMemberRole::Owner,
        ]);

        return $this->mapper->projectToResource($project, $ownerMembership);
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
