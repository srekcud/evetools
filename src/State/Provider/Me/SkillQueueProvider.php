<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\SkillQueueEntryResource;
use App\ApiResource\Me\SkillQueueResource;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<SkillQueueResource>
 */
class SkillQueueProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly TypeNameResolver $typeNameResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SkillQueueResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new SkillQueueResource();
        $queues = [];

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $queue = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/skillqueue/",
                    $token
                );

                // Find the currently training skill (first with finish_date in the future)
                $now = new \DateTimeImmutable();
                $currentSkill = null;

                foreach ($queue as $item) {
                    if (!isset($item['finish_date'])) {
                        continue;
                    }
                    $finishDate = new \DateTimeImmutable($item['finish_date']);
                    if ($finishDate > $now) {
                        $currentSkill = $item;
                        break;
                    }
                }

                if ($currentSkill !== null) {
                    $skillName = $this->resolveTypeName($currentSkill['skill_id']);

                    $entry = new SkillQueueEntryResource();
                    $entry->characterId = $character->getId()?->toRfc4122() ?? '';
                    $entry->skillId = $currentSkill['skill_id'];
                    $entry->skillName = $skillName;
                    $entry->finishedLevel = $currentSkill['finished_level'] ?? null;
                    $entry->finishDate = $currentSkill['finish_date'];
                    $entry->queueSize = count(array_filter(
                        $queue,
                        fn ($i) => isset($i['finish_date']) && new \DateTimeImmutable($i['finish_date']) > $now
                    ));

                    $queues[] = $entry;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $resource->skillQueues = $queues;

        return $resource;
    }

    private function resolveTypeName(int $typeId): string
    {
        return $this->typeNameResolver->resolve($typeId);
    }
}
