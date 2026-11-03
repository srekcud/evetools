<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mining\CorporationMiningExtractionsResource;
use App\Entity\User;
use App\Message\SyncCorporationMiningExtractions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<mixed, CorporationMiningExtractionsResource>
 */
final readonly class SyncCorporationMiningExtractionsProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CorporationMiningExtractionsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        foreach ($user->getCharacterCorporationIds() as $corporationId) {
            $this->messageBus->dispatch(new SyncCorporationMiningExtractions($corporationId));
        }

        return new CorporationMiningExtractionsResource();
    }
}
