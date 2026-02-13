<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\AddTypeInput;
use App\ApiResource\Pve\AmmoTypeResource;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<AddTypeInput, AmmoTypeResource>
 */
class AddAmmoTypeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AmmoTypeResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof AddTypeInput);

        if ($data->typeId <= 0) {
            throw new BadRequestHttpException('Invalid type ID');
        }

        $type = $this->invTypeRepository->find($data->typeId);
        if ($type === null) {
            throw new NotFoundHttpException('Type not found');
        }

        $settings = $this->settingsRepository->getOrCreate($user);
        $settings->addAmmoTypeId($data->typeId);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        $resource = new AmmoTypeResource();
        $resource->id = $data->typeId;
        $resource->typeName = $type->getTypeName();

        return $resource;
    }
}
