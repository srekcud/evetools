<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\LineRentalRatesResource;
use App\ApiResource\Input\Industry\Project\UpdateLineRentalRatesInput;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UpdateLineRentalRatesInput, LineRentalRatesResource>
 */
class UpdateLineRentalRatesProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LineRentalRatesResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof UpdateLineRentalRatesInput);

        $user->setLineRentalRates($data->rates);
        $this->entityManager->flush();

        $resource = new LineRentalRatesResource();
        $resource->id = 'default';
        $resource->rates = $user->getLineRentalRates();

        return $resource;
    }
}
