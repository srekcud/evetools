<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\MaterialValuePreviewResource;
use App\Entity\User;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\PaidTransactionUnits;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MaterialValuePreviewResource>
 */
final readonly class MaterialValuePreviewProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private IndustryProjectParticipantRepository $memberRepository,
        private ProjectAccessChecker $accessChecker,
        private ContributorPaidPurchaseReader $paidPurchases,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MaterialValuePreviewResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of((string) ($uriVariables['projectId'] ?? ''), 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $line = $this->bomLineRepository->findOneBy([
            'id' => UuidOrNotFound::of((string) ($uriVariables['id'] ?? ''), 'Line not found'),
            'project' => $project,
        ]);
        if ($line === null || $line->isJob()) {
            throw new NotFoundHttpException('Line not found');
        }

        $quantity = $this->quantityOf($context);
        $member = $this->memberRepository->findOneBy(['project' => $project, 'user' => $user, 'status' => ParticipantStatus::Accepted]);
        $paid = null === $member ? null : $this->paidPurchases->read($member, $line->getTypeId());
        $matches = null === $paid ? [] : $paid->allocate($quantity);
        $matchedQuantity = array_sum(array_map(static fn (PaidTransactionUnits $units): int => $units->quantity, $matches));

        $resource = new MaterialValuePreviewResource();
        $resource->id = $line->getId()->toRfc4122();
        $resource->matchedQuantity = $matchedQuantity;
        $resource->matchedValue = [] === $matches ? null : (float) array_sum(array_map(static fn (PaidTransactionUnits $units): float => $units->totalPrice(), $matches));
        $resource->unmatchedQuantity = $quantity - $matchedQuantity;
        $resource->matches = array_map(static fn (PaidTransactionUnits $units): array => [
            'date' => $units->transaction->getDate()->format(\DateTimeInterface::ATOM),
            'quantity' => $units->quantity,
            'unitPrice' => $units->transaction->getUnitPrice(),
        ], $matches);

        return $resource;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function quantityOf(array $context): int
    {
        $requested = $context['request']?->query->get('quantity');
        if (!\is_string($requested) || !ctype_digit($requested) || (int) $requested <= 0) {
            throw new BadRequestHttpException('The quantity must be a positive integer');
        }

        return (int) $requested;
    }
}
