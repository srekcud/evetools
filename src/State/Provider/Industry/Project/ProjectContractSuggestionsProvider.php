<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectContractSuggestionsResource;
use App\Entity\User;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\Contract\ContractSaleCandidate;
use App\Service\Industry\Project\Contract\ContractSaleReader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectContractSuggestionsResource>
 */
final readonly class ProjectContractSuggestionsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ContractSaleReader $contractSaleReader,
        private CachedWalletJournalEntryRepository $journalRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectContractSuggestionsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        $project = $projectId === null ? null : $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        $read = $this->contractSaleReader->read($user, $project);
        $feesByContractId = $this->journalRepository->contractFeesByContractId(array_map(
            static fn (ContractSaleCandidate $candidate): int => $candidate->contractId,
            $read->candidates,
        ));

        $resource = new ProjectContractSuggestionsResource();
        $resource->projectId = $projectId;
        $resource->candidates = array_map(static fn (ContractSaleCandidate $candidate): array => [
            'contractId' => $candidate->contractId,
            'typeId' => $candidate->typeId,
            'typeName' => $candidate->typeName,
            'quantity' => $candidate->quantity,
            'price' => $candidate->price,
            'unitPrice' => $candidate->unitPrice(),
            'completedAt' => $candidate->completedAt->format(\DateTimeInterface::ATOM),
            'sellerCharacterId' => $candidate->sellerEveCharacterId,
            'sellerCharacterName' => $candidate->sellerCharacterName,
            'contractFees' => $feesByContractId[$candidate->contractId] ?? null,
        ], $read->candidates);
        $resource->unreadableCharacters = array_map(
            static fn (int $characterId, string $reason): array => ['characterId' => $characterId, 'reason' => $reason],
            array_keys($read->unreadableReasonByEveCharacterId),
            $read->unreadableReasonByEveCharacterId,
        );

        return $resource;
    }
}
