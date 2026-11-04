<?php

declare(strict_types=1);

namespace App\State\Processor\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\ApiResource\MoonTax\MoonTaxSyncResource;
use App\Message\SyncMoonTax;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonTaxManagerAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<EmptyInput, MoonTaxSyncResource>
 */
final readonly class TriggerMoonTaxCorporationSyncProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MoonTaxManagerAccess $managerAccess,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MoonTaxSyncResource
    {
        $corporationId = (int) $uriVariables['corporationId'];
        $this->managerAccess->assertManages(MiningOperationRequest::userOf($this->security), $corporationId);

        $this->messageBus->dispatch(new SyncMoonTax($corporationId));

        $resource = new MoonTaxSyncResource();
        $resource->corporation = MoonTaxCorporationResource::identifiedBy($corporationId);
        $resource->corporationId = $corporationId;

        return $resource;
    }
}
