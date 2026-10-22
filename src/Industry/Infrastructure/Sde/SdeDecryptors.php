<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Entity\Sde\InvType;
use App\Industry\Application\Decryptors;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\Multiplier;
use App\Repository\Sde\InvTypeRepository;

final readonly class SdeDecryptors implements Decryptors
{
    private const int DECRYPTOR_GROUP_ID = 1304;
    private const int ATTRIBUTE_INVENTION_PROBABILITY_MULTIPLIER = 1112;
    private const int ATTRIBUTE_INVENTION_ME_MODIFIER = 1113;
    private const int ATTRIBUTE_INVENTION_TE_MODIFIER = 1114;
    private const int ATTRIBUTE_INVENTION_MAX_RUN_MODIFIER = 1124;

    public function __construct(
        private InvTypeRepository $invTypeRepository,
        private DogmaAttributeValues $dogmaAttributeValues,
    ) {
    }

    public function all(): array
    {
        return array_map(
            fn (InvType $type): Decryptor => $this->decryptorOf($type->getTypeId()),
            $this->invTypeRepository->findBy(['group' => self::DECRYPTOR_GROUP_ID], ['typeId' => 'ASC']),
        );
    }

    private function decryptorOf(int $typeId): Decryptor
    {
        $values = $this->dogmaAttributeValues->of($typeId, [
            self::ATTRIBUTE_INVENTION_PROBABILITY_MULTIPLIER,
            self::ATTRIBUTE_INVENTION_ME_MODIFIER,
            self::ATTRIBUTE_INVENTION_TE_MODIFIER,
            self::ATTRIBUTE_INVENTION_MAX_RUN_MODIFIER,
        ]);

        return new Decryptor(
            $typeId,
            new Multiplier(self::valueOf($values, self::ATTRIBUTE_INVENTION_PROBABILITY_MULTIPLIER, $typeId)),
            (int) self::valueOf($values, self::ATTRIBUTE_INVENTION_MAX_RUN_MODIFIER, $typeId),
            (int) self::valueOf($values, self::ATTRIBUTE_INVENTION_ME_MODIFIER, $typeId),
            (int) self::valueOf($values, self::ATTRIBUTE_INVENTION_TE_MODIFIER, $typeId),
        );
    }

    /**
     * @param array<int, float> $values by attribute id
     */
    private static function valueOf(array $values, int $attributeId, int $typeId): float
    {
        if (!\array_key_exists($attributeId, $values)) {
            throw new \UnexpectedValueException(\sprintf('Decryptor %d has no dogma attribute %d: the SDE is incomplete.', $typeId, $attributeId));
        }

        return $values[$attributeId];
    }
}
