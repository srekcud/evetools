<?php

declare(strict_types=1);

namespace App\State\Provider\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MoonTax\MoonTaxMineResource;
use App\Entity\Character;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonTaxStatementReader;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MoonTaxMineResource>
 */
final readonly class MoonTaxMineProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MoonTaxStatementReader $statementReader,
        private MoonTaxResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MoonTaxMineResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = MiningOperationRequest::userOf($this->security);
        $characterIds = array_values(array_map(
            static fn (Character $character): int => $character->getEveCharacterId(),
            $user->getCharacters()->toArray(),
        ));
        if ($characterIds === []) {
            return [];
        }

        return array_map($this->mapper->mineToResource(...), $this->statementReader->ofCharacters($characterIds));
    }
}
