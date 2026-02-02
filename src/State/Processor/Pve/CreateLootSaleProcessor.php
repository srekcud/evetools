<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\CreateLootSaleInput;
use App\ApiResource\Pve\LootSaleResource;
use App\Entity\PveIncome;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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

        if (!$data instanceof CreateLootSaleInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        $income = new PveIncome();
        $income->setUser($user);
        $income->setType($data->type ?? PveIncome::TYPE_LOOT_SALE);
        $income->setDescription($data->description);
        $income->setAmount($data->amount);

        if ($data->date !== null) {
            $income->setDate(new \DateTimeImmutable($data->date));
        }

        $this->entityManager->persist($income);
        $this->entityManager->flush();

        $resource = new LootSaleResource();
        $resource->id = $income->getId()?->toRfc4122() ?? '';
        $resource->type = $income->getType();
        $resource->description = $income->getDescription();
        $resource->amount = $income->getAmount();
        $resource->date = $income->getDate()->format('Y-m-d');

        return $resource;
    }
}
