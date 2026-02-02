<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class DeleteLootSaleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $lootSale = $this->incomeRepository->find($uriVariables['id']);

        if ($lootSale === null) {
            throw new NotFoundHttpException('Loot sale not found');
        }

        if ($lootSale->getUser() !== $user) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        // Add to declined list so it doesn't reappear on next scan
        $settings = $this->settingsRepository->getOrCreate($user);
        if ($lootSale->getContractId() !== null) {
            $settings->addDeclinedContractId($lootSale->getContractId());
        }
        if ($lootSale->getTransactionId() !== null) {
            $settings->addDeclinedLootSaleTransactionId($lootSale->getTransactionId());
        }
        $this->entityManager->persist($settings);

        $this->entityManager->remove($lootSale);
        $this->entityManager->flush();
    }
}
