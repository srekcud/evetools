<?php

declare(strict_types=1);

namespace App\State\Processor\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Escalation\EscalationResource;
use App\Entity\Escalation;
use App\Entity\User;
use App\Enum\EscalationBmStatus;
use App\Enum\EscalationSaleStatus;
use App\Enum\EscalationVisibility;
use App\Repository\EscalationRepository;
use App\Service\Mercure\MercurePublisherService;
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
        private readonly MercurePublisherService $mercurePublisher,
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

        if (isset($payload['visibility'])) {
            $parsedVisibility = EscalationVisibility::tryFrom($payload['visibility']);
            if ($parsedVisibility !== null) {
                $escalation->setVisibility($parsedVisibility);
            }
        }

        if (isset($payload['bmStatus'])) {
            $parsedBmStatus = EscalationBmStatus::tryFrom($payload['bmStatus']);
            if ($parsedBmStatus !== null) {
                $escalation->setBmStatus($parsedBmStatus);
            }
        }

        if (isset($payload['saleStatus'])) {
            $parsedSaleStatus = EscalationSaleStatus::tryFrom($payload['saleStatus']);
            if ($parsedSaleStatus !== null) {
                $escalation->setSaleStatus($parsedSaleStatus);
            }
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

        // Publish Mercure event for non-personal escalations
        $visibility = $escalation->getVisibility();
        if ($visibility !== EscalationVisibility::Perso) {
            $this->mercurePublisher->publishEscalationEvent(
                'updated',
                [
                    'id' => $escalation->getId()?->toRfc4122(),
                    'type' => $escalation->getType(),
                    'solarSystemName' => $escalation->getSolarSystemName(),
                    'characterName' => $escalation->getCharacterName(),
                    'visibility' => $visibility->value,
                    'saleStatus' => $escalation->getSaleStatus()->value,
                    'bmStatus' => $escalation->getBmStatus()->value,
                ],
                $escalation->getCorporationId(),
                $escalation->getAllianceId(),
                $visibility->value,
            );
        }

        return EscalationResourceMapper::toResource($escalation, true);
    }
}
