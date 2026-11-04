<?php

declare(strict_types=1);

namespace App\State\Processor\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MoonTax\MoonTaxStatementResource;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonTaxManagerAccess;
use App\Service\MoonTax\MoonTaxStatementActions;
use App\Service\MoonTax\MoonTaxStatementReader;
use App\State\Provider\MoonTax\MoonTaxResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<EmptyInput, MoonTaxStatementResource>
 */
final readonly class MarkMoonTaxStatementPaidProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MoonTaxManagerAccess $managerAccess,
        private MoonTaxStatementActions $actions,
        private MoonTaxStatementReader $statementReader,
        private MoonTaxResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MoonTaxStatementResource
    {
        $user = MiningOperationRequest::userOf($this->security);
        $corporationId = (int) $uriVariables['corporationId'];
        $this->managerAccess->assertManages($user, $corporationId);

        $statement = $this->actions->statementOf($corporationId, $uriVariables['id']);
        $this->actions->markPaid($statement, $user);

        return $this->mapper->statementToResource($this->statementReader->viewOfStatement($statement), null);
    }
}
