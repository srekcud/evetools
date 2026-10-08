<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Repository\Sde\DgmTypeAttributeRepository;

/**
 * Dogma attribute values of a type (sde_dgm_type_attributes). The importer stores a whole number in value_int and
 * any other number in value_float (SdeDogmaImporter): both columns are read.
 */
final readonly class DogmaAttributeValues
{
    public function __construct(private DgmTypeAttributeRepository $typeAttributeRepository)
    {
    }

    /**
     * @param list<int> $attributeIds
     *
     * @return array<int, float> value by attribute id; an attribute the type does not carry is absent
     */
    public function of(int $typeId, array $attributeIds): array
    {
        $values = [];
        foreach ($this->typeAttributeRepository->findBy(['typeId' => $typeId, 'attributeId' => $attributeIds]) as $typeAttribute) {
            $value = $typeAttribute->getValue();
            if (null === $value) {
                throw new \UnexpectedValueException(\sprintf('Dogma attribute %d of type %d has no value.', $typeAttribute->getAttributeId(), $typeId));
            }
            $values[$typeAttribute->getAttributeId()] = (float) $value;
        }

        return $values;
    }
}
