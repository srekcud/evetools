<?php

declare(strict_types=1);

namespace App\State\Processor\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Escalation\EscalationResource;
use App\ApiResource\Input\Escalation\CreateEscalationInput;
use App\Entity\Escalation;
use App\Entity\User;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\Escalation\EscalationResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateEscalationInput, EscalationResource>
 */
class CreateEscalationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EscalationResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateEscalationInput);

        // Find the character
        $character = null;
        foreach ($user->getCharacters() as $c) {
            if ($c->getEveCharacterId() === $data->characterId) {
                $character = $c;
                break;
            }
        }

        if ($character === null) {
            throw new BadRequestHttpException('Character not found');
        }

        $timerHours = min(72.0, max(0.1, $data->timerHours));

        // Validate visibility
        $validVisibilities = [
            Escalation::VISIBILITY_PERSO,
            Escalation::VISIBILITY_CORP,
            Escalation::VISIBILITY_ALLIANCE,
            Escalation::VISIBILITY_PUBLIC,
        ];
        $visibility = in_array($data->visibility, $validVisibilities, true)
            ? $data->visibility
            : Escalation::VISIBILITY_PERSO;

        $escalation = new Escalation();
        $escalation->setUser($user);
        $escalation->setCharacterId($character->getEveCharacterId());
        $escalation->setCharacterName($character->getName());
        $escalation->setType($data->type);
        $escalation->setSolarSystemId($data->solarSystemId);
        $escalation->setSolarSystemName($data->solarSystemName);
        $escalation->setSecurityStatus($data->securityStatus);
        $escalation->setPrice($data->price);
        $escalation->setCorporationId($character->getCorporationId());
        $escalation->setAllianceId($character->getAllianceId());
        $escalation->setNotes($data->notes);
        $escalation->setVisibility($visibility);
        $escalation->setExpiresAt(new \DateTimeImmutable(sprintf('+%d minutes', (int) ($timerHours * 60))));

        $this->em->persist($escalation);
        $this->em->flush();

        // Publish Mercure event for non-personal escalations
        if ($visibility !== Escalation::VISIBILITY_PERSO) {
            $this->mercurePublisher->publishEscalationEvent(
                'created',
                [
                    'id' => $escalation->getId()?->toRfc4122(),
                    'type' => $escalation->getType(),
                    'solarSystemName' => $escalation->getSolarSystemName(),
                    'characterName' => $escalation->getCharacterName(),
                    'visibility' => $visibility,
                ],
                $escalation->getCorporationId(),
                $escalation->getAllianceId(),
                $visibility,
            );
        }

        return EscalationResourceMapper::toResource($escalation, true);
    }
}
