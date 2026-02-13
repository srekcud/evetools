<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\CreateExpenseInput;
use App\ApiResource\Pve\ExpenseResource;
use App\Entity\PveExpense;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateExpenseInput, ExpenseResource>
 */
class CreateExpenseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateExpenseInput);

        $validTypes = [PveExpense::TYPE_FUEL, PveExpense::TYPE_AMMO, PveExpense::TYPE_CRAB_BEACON, PveExpense::TYPE_OTHER];
        if (!in_array($data->type, $validTypes, true)) {
            throw new BadRequestHttpException('Invalid expense type');
        }

        $expense = new PveExpense();
        $expense->setUser($user);
        $expense->setType($data->type);
        $expense->setDescription($data->description);
        $expense->setAmount($data->amount);

        if ($data->date !== null) {
            $expense->setDate(new \DateTimeImmutable($data->date));
        }

        $this->entityManager->persist($expense);
        $this->entityManager->flush();

        $resource = new ExpenseResource();
        $resource->id = $expense->getId()?->toRfc4122() ?? '';
        $resource->type = $expense->getType();
        $resource->description = $expense->getDescription();
        $resource->amount = $expense->getAmount();
        $resource->date = $expense->getDate()->format('Y-m-d');

        return $resource;
    }
}
