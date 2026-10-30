<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\ImportLootSalesInput;
use App\ApiResource\Pve\ImportResultResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\Pve\CorpProjectAccountedQuantities;
use App\Service\Pve\CorpProjectContributionAccounting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ImportLootSalesInput, ImportResultResource>
 */
class ImportLootSalesProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CorpProjectContributionAccounting $contributionAccounting,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ImportResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof ImportLootSalesInput);

        $imported = 0;
        $rejectedContributions = 0;

        $settings = $this->settingsRepository->getOrCreate($user);
        $accountedQuantities = $this->contributionAccounting->accountedQuantitiesOf($user);
        foreach ($data->declined as $item) {
            if ($this->isCorpProjectContribution($item)) {
                $pendingContribution = $this->pendingContributionOf($item, $accountedQuantities);
                if ($pendingContribution === null) {
                    $rejectedContributions++;
                } else {
                    $settings->addDeclinedCorpProjectQuantity($pendingContribution['projectId'], $pendingContribution['characterId'], $pendingContribution['quantity']);
                }
                continue;
            }

            $transactionId = isset($item['transactionId']) ? (int) $item['transactionId'] : 0;

            if ($transactionId !== 0) {
                $settings->addDeclinedLootSaleTransactionId($transactionId);
            }
        }
        $this->entityManager->persist($settings);

        $importedTransactionIds = $this->incomeRepository->getImportedTransactionIds($user);

        foreach ($data->sales as $saleData) {
            $transactionId = isset($saleData['transactionId']) ? (int) $saleData['transactionId'] : null;

            if ($transactionId && in_array($transactionId, $importedTransactionIds, true)) {
                continue;
            }

            $pendingContribution = null;
            if ($this->isCorpProjectContribution($saleData)) {
                $pendingContribution = $this->pendingContributionOf($saleData, $accountedQuantities);
                if ($pendingContribution === null) {
                    $rejectedContributions++;
                    continue;
                }
            }

            $income = new PveIncome();
            $income->setUser($user);
            $income->setType(isset($saleData['type']) ? (PveIncomeType::tryFrom($saleData['type']) ?? PveIncomeType::LootSale) : PveIncomeType::LootSale);
            $income->setDescription($saleData['typeName']);
            $income->setAmount((float) $saleData['price']);
            $income->setDate(new \DateTimeImmutable($saleData['dateIssued']));

            if ($transactionId !== null && $transactionId !== 0) {
                $income->setTransactionId($transactionId);
            }

            if ($pendingContribution !== null) {
                $income->setCorpProjectId($pendingContribution['projectId']);
                $income->setCorpProjectCharacterId($pendingContribution['characterId']);
                $income->setCorpProjectQuantity($pendingContribution['quantity']);
            }

            $this->entityManager->persist($income);
            $imported++;

            if ($transactionId) {
                $importedTransactionIds[] = $transactionId;
            }
        }

        $this->entityManager->flush();

        $resource = new ImportResultResource();
        $resource->imported = $imported;
        $resource->rejectedContributions = $rejectedContributions;

        return $resource;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isCorpProjectContribution(array $item): bool
    {
        return ($item['type'] ?? null) === PveIncomeType::CorpProject->value;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{projectId: string, characterId: int, quantity: int}|null
     */
    private function pendingContributionOf(array $item, CorpProjectAccountedQuantities $accountedQuantities): ?array
    {
        if (!isset($item['projectId'], $item['characterId'], $item['quantity'], $item['accountedQuantity'])) {
            return null;
        }

        $projectId = (string) $item['projectId'];
        $characterId = (int) $item['characterId'];
        if ($accountedQuantities->isLegacyIdentifierDeclined($projectId, $characterId)
            || $accountedQuantities->quantityOf($projectId, $characterId) !== (int) $item['accountedQuantity']) {
            return null;
        }

        return ['projectId' => $projectId, 'characterId' => $characterId, 'quantity' => (int) $item['quantity']];
    }
}
