<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\ImportLootContractsInput;
use App\ApiResource\Pve\ImportResultResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ImportLootContractsInput, ImportResultResource>
 */
class ImportLootContractsProcessor implements ProcessorInterface
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

        assert($data instanceof ImportLootContractsInput);

        $imported = 0;
        $rejectedZeroPrice = 0;

        $settings = $this->settingsRepository->getOrCreate($user);
        foreach ($data->declined as $item) {
            $contractId = isset($item['contractId']) ? (int) $item['contractId'] : 0;
            if ($contractId > 0) {
                $settings->addDeclinedContractId($contractId);
            }
        }
        $this->entityManager->persist($settings);

        $importedContractIds = $this->incomeRepository->getImportedContractIds($user);

        foreach ($data->contracts as $contractData) {
            $contractId = (int) $contractData['contractId'];
            $price = (float) $contractData['price'];

            if (in_array($contractId, $importedContractIds, true)) {
                continue;
            }

            if ($price <= 0) {
                $settings->addDeclinedContractId($contractId);
                $rejectedZeroPrice++;
                continue;
            }

            $description = $contractData['description'];
            if (strlen($description) > 250) {
                $description = substr($description, 0, 247) . '...';
            }

            $income = new PveIncome();
            $income->setUser($user);
            $income->setType(PveIncome::TYPE_LOOT_CONTRACT);
            $income->setDescription($description);
            $income->setAmount($price);
            $income->setDate(new \DateTimeImmutable($contractData['date']));
            $income->setContractId($contractId);

            $this->entityManager->persist($income);
            $importedContractIds[] = $contractId;
            $imported++;
        }

        $this->entityManager->flush();

        $resource = new ImportResultResource();
        $resource->imported = $imported;
        $resource->rejectedZeroPrice = $rejectedZeroPrice;

        return $resource;
    }
}
