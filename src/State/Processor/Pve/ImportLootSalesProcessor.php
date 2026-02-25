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

        $settings = $this->settingsRepository->getOrCreate($user);
        foreach ($data->declined as $item) {
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

            $income = new PveIncome();
            $income->setUser($user);
            $income->setType(isset($saleData['type']) ? (PveIncomeType::tryFrom($saleData['type']) ?? PveIncomeType::LootSale) : PveIncomeType::LootSale);
            $income->setDescription($saleData['typeName']);
            $income->setAmount((float) $saleData['price']);
            $income->setDate(new \DateTimeImmutable($saleData['dateIssued']));

            if ($transactionId !== null && $transactionId !== 0) {
                $income->setTransactionId($transactionId);
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

        return $resource;
    }
}
