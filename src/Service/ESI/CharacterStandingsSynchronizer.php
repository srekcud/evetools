<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\CachedCharacterStanding;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Repository\CachedCharacterStandingRepository;
use Doctrine\ORM\EntityManagerInterface;

class CharacterStandingsSynchronizer
{
    private const string IGNORED_FROM_TYPE = 'agent';

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly CachedCharacterStandingRepository $standingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function sync(Character $character, EveToken $token): void
    {
        $characterId = $character->getEveCharacterId();
        $esiStandings = $this->esiClient->get("/characters/{$characterId}/standings/", $token);

        $incomingStandings = $this->validatedStandingsByKey($esiStandings, $characterId);

        $now = new \DateTimeImmutable();

        $storedStandingsByKey = [];
        foreach ($this->standingRepository->findBy(['character' => $character]) as $stored) {
            $storedStandingsByKey[$this->key($stored->getFromType(), $stored->getFromId())] = $stored;
        }

        foreach ($incomingStandings as $key => $incoming) {
            $standing = $storedStandingsByKey[$key] ?? null;
            if ($standing === null) {
                $standing = (new CachedCharacterStanding())
                    ->setCharacter($character)
                    ->setFromId($incoming['fromId'])
                    ->setFromType($incoming['fromType']);
                $this->entityManager->persist($standing);
            }
            $standing->setStanding($incoming['standing'])->setCachedAt($now);
            unset($storedStandingsByKey[$key]);
        }

        foreach ($storedStandingsByKey as $vanished) {
            $this->entityManager->remove($vanished);
        }

        $character->setStandingsSyncedAt($now);
        $this->entityManager->flush();
    }

    /**
     * @param array<mixed> $esiStandings
     *
     * @return array<string, array{fromId: int, fromType: string, standing: float}>
     */
    private function validatedStandingsByKey(array $esiStandings, int $characterId): array
    {
        if (!array_is_list($esiStandings)) {
            throw new \UnexpectedValueException("ESI standings response of character {$characterId} is not a list");
        }

        $standingsByKey = [];
        foreach ($esiStandings as $index => $entry) {
            if (!is_array($entry)) {
                throw new \UnexpectedValueException("ESI standings entry {$index} of character {$characterId} is not an object");
            }
            if (!isset($entry['from_id']) || !is_int($entry['from_id'])) {
                throw new \UnexpectedValueException("ESI standings entry {$index} of character {$characterId} has no integer 'from_id'");
            }
            if (!isset($entry['from_type']) || !is_string($entry['from_type'])) {
                throw new \UnexpectedValueException("ESI standings entry {$index} of character {$characterId} has no string 'from_type'");
            }
            if (!isset($entry['standing']) || !(is_float($entry['standing']) || is_int($entry['standing']))) {
                throw new \UnexpectedValueException("ESI standings entry {$index} of character {$characterId} has no numeric 'standing'");
            }

            if ($entry['from_type'] === self::IGNORED_FROM_TYPE) {
                continue;
            }

            $standingsByKey[$this->key($entry['from_type'], $entry['from_id'])] = [
                'fromId' => $entry['from_id'],
                'fromType' => $entry['from_type'],
                'standing' => (float) $entry['standing'],
            ];
        }

        return $standingsByKey;
    }

    private function key(string $fromType, int $fromId): string
    {
        return $fromType . ':' . $fromId;
    }
}
