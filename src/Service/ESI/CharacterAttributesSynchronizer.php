<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\CachedCharacterAttributes;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Repository\CachedCharacterAttributesRepository;
use Doctrine\ORM\EntityManagerInterface;

class CharacterAttributesSynchronizer
{
    private const array ATTRIBUTE_NAMES = ['intelligence', 'memory', 'perception', 'willpower', 'charisma'];

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly CachedCharacterAttributesRepository $attributesRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function sync(Character $character, EveToken $token): void
    {
        $characterId = $character->getEveCharacterId();
        $esiAttributes = $this->esiClient->get("/characters/{$characterId}/attributes/", $token);

        $values = $this->validatedValues($esiAttributes, $characterId);

        $attributes = $this->attributesRepository->findOneBy(['character' => $character]);
        if ($attributes === null) {
            $attributes = (new CachedCharacterAttributes())->setCharacter($character);
            $this->entityManager->persist($attributes);
        }

        $attributes
            ->setAttributes($values['intelligence'], $values['memory'], $values['perception'], $values['willpower'], $values['charisma'])
            ->setCachedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    /**
     * @param array<mixed> $esiAttributes
     *
     * @return array<string, int>
     */
    private function validatedValues(array $esiAttributes, int $characterId): array
    {
        $values = [];
        foreach (self::ATTRIBUTE_NAMES as $name) {
            if (!isset($esiAttributes[$name]) || !is_int($esiAttributes[$name])) {
                throw new \UnexpectedValueException("ESI attributes response of character {$characterId} has no integer '{$name}'");
            }
            $values[$name] = $esiAttributes[$name];
        }

        return $values;
    }
}
