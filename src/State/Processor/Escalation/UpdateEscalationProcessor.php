<?php

declare(strict_types=1);

namespace App\State\Processor\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Escalation\EscalationResource;
use App\Entity\Escalation;
use App\Entity\User;
use App\Repository\EscalationRepository;
use App\State\Provider\Escalation\EscalationResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<EscalationResource, EscalationResource>
 */
class UpdateEscalationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EscalationRepository $escalationRepository,
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EscalationResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = $uriVariables['id'] ?? null;
        $escalation = $this->escalationRepository->find(Uuid::fromString($id));

        if ($escalation === null) {
            throw new NotFoundHttpException('Escalation not found');
        }

        if (!$escalation->isOwnedBy($user)) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $request = $this->requestStack->getCurrentRequest();
        $payload = json_decode($request?->getContent() ?? '{}', true);

        $validVisibilities = [Escalation::VISIBILITY_PERSO, Escalation::VISIBILITY_CORP, Escalation::VISIBILITY_ALLIANCE, Escalation::VISIBILITY_PUBLIC];
        $validBmStatuses = [Escalation::BM_NOUVEAU, Escalation::BM_BM];
        $validSaleStatuses = [Escalation::SALE_ENVENTE, Escalation::SALE_VENDU];

        if (isset($payload['visibility']) && in_array($payload['visibility'], $validVisibilities, true)) {
            $escalation->setVisibility($payload['visibility']);
        }

        if (isset($payload['bmStatus']) && in_array($payload['bmStatus'], $validBmStatuses, true)) {
            $escalation->setBmStatus($payload['bmStatus']);
        }

        if (isset($payload['saleStatus']) && in_array($payload['saleStatus'], $validSaleStatuses, true)) {
            $escalation->setSaleStatus($payload['saleStatus']);
        }

        if (isset($payload['price']) && is_int($payload['price'])) {
            $escalation->setPrice($payload['price']);
        }

        if (isset($payload['type']) && is_string($payload['type']) && strlen($payload['type']) <= 100) {
            $escalation->setType($payload['type']);
        }

        if (array_key_exists('notes', $payload)) {
            $escalation->setNotes($payload['notes']);
        }

        $this->em->flush();

        return EscalationResourceMapper::toResource($escalation, true);
    }
}
