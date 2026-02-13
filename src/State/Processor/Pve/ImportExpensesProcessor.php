<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\ImportExpensesInput;
use App\ApiResource\Pve\ImportResultResource;
use App\Entity\PveExpense;
use App\Entity\User;
use App\Repository\PveExpenseRepository;
use App\Repository\UserPveSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ImportExpensesInput, ImportResultResource>
 */
class ImportExpensesProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveExpenseRepository $expenseRepository,
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

        assert($data instanceof ImportExpensesInput);

        $imported = 0;

        $settings = $this->settingsRepository->getOrCreate($user);
        foreach ($data->declined as $item) {
            $contractId = isset($item['contractId']) ? (int) $item['contractId'] : 0;
            $transactionId = isset($item['transactionId']) ? (int) $item['transactionId'] : 0;

            if ($contractId > 0) {
                $settings->addDeclinedContractId($contractId);
            }
            if ($transactionId > 0) {
                $settings->addDeclinedTransactionId($transactionId);
            }
        }
        $this->entityManager->persist($settings);

        $importedContractIds = $this->expenseRepository->getImportedContractIds($user);
        $importedTransactionIds = $this->expenseRepository->getImportedTransactionIds($user);

        foreach ($data->expenses as $expenseData) {
            $contractId = isset($expenseData['contractId']) ? (int) $expenseData['contractId'] : null;
            $transactionId = isset($expenseData['transactionId']) ? (int) $expenseData['transactionId'] : null;

            if ($contractId && in_array($contractId, $importedContractIds, true)) {
                continue;
            }
            if ($transactionId && in_array($transactionId, $importedTransactionIds, true)) {
                continue;
            }

            $description = $expenseData['typeName'];

            $expense = new PveExpense();
            $expense->setUser($user);
            $expense->setType($expenseData['type']);
            $expense->setDescription($description);
            $expense->setAmount((float) $expenseData['price']);
            $expense->setDate(new \DateTimeImmutable($expenseData['dateIssued']));

            if ($contractId && $contractId > 0) {
                $expense->setContractId($contractId);
            }
            if ($transactionId && $transactionId > 0) {
                $expense->setTransactionId($transactionId);
            }

            $this->entityManager->persist($expense);
            $imported++;

            if ($contractId) {
                $importedContractIds[] = $contractId;
            }
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
