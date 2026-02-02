<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\ResetDeclinedInput;
use App\ApiResource\Pve\SuccessResource;
use App\Entity\User;
use App\Repository\UserPveSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ResetDeclinedInput, SuccessResource>
 */
class ResetDeclinedProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SuccessResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$data instanceof ResetDeclinedInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        $keepContractIds = array_map('intval', $data->keepContractIds);
        $keepTransactionIds = array_map('intval', $data->keepTransactionIds);

        $settings = $this->settingsRepository->getOrCreate($user);
        $settings->clearDeclinedExcept($keepContractIds, $keepTransactionIds);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        $resource = new SuccessResource();
        $resource->success = true;

        return $resource;
    }
}
