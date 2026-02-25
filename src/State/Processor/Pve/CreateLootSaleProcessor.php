<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\CreateLootSaleInput;
use App\ApiResource\Pve\LootSaleResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateLootSaleInput, LootSaleResource>
 */
class CreateLootSaleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LootSaleResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateLootSaleInput);

        $income = new PveIncome();
        $income->setUser($user);
        $income->setType($data->type !== null ? (PveIncomeType::tryFrom($data->type) ?? PveIncomeType::LootSale) : PveIncomeType::LootSale);
        $income->setDescription($data->description);
        $income->setAmount($data->amount);

        if ($data->date !== null) {
            $income->setDate(new \DateTimeImmutable($data->date));
        }

        $this->entityManager->persist($income);
        $this->entityManager->flush();

        $resource = new LootSaleResource();
        $resource->id = $income->getId()?->toRfc4122() ?? '';
        $resource->type = $income->getType()->value;
        $resource->description = $income->getDescription();
        $resource->amount = $income->getAmount();
        $resource->date = $income->getDate()->format('Y-m-d');

        return $resource;
    }
}
