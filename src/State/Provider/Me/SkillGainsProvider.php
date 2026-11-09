<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\SkillGainsResource;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\Industry\Skills\SkillGainEstimator;
use App\Service\Industry\Skills\SkillGainLine;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<SkillGainsResource>
 */
class SkillGainsProvider implements ProviderInterface
{
    private const int PERIOD_DAYS = 30;

    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
        private readonly SkillGainEstimator $skillGainEstimator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SkillGainsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $characterId = (string) $uriVariables['characterId'];
        $character = Uuid::isValid($characterId) ? $this->characterRepository->find(Uuid::fromString($characterId)) : null;
        if ($character === null || $character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        $to = new \DateTimeImmutable();
        $from = $to->modify('-' . self::PERIOD_DAYS . ' days');
        $report = $this->skillGainEstimator->estimate($user, $character, $from, $to);

        $resource = new SkillGainsResource();
        $resource->characterId = $characterId;
        $resource->period = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'days' => self::PERIOD_DAYS];
        $resource->iskPerHour = $report->iskPerHour;
        $resource->lines = array_map(static fn (SkillGainLine $line): array => [
            'skillId' => $line->skillId,
            'currentLevel' => $line->currentLevel,
            'targetLevel' => $line->targetLevel,
            'effect' => $line->effect->value,
            'hoursSavedPerMonth' => $line->hoursSavedPerMonth,
            'monthlyGain' => $line->monthlyGain,
            'gainUnknownReason' => $line->gainUnknownReason?->value,
            'trainingSeconds' => $line->trainingSeconds,
            'trainingUnknownReason' => $line->trainingUnknownReason?->value,
            'gainPerTrainingDay' => $line->gainPerTrainingDay,
        ], $report->lines);

        return $resource;
    }
}
