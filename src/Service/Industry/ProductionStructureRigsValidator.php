<?php

declare(strict_types=1);

namespace App\Service\Industry;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final readonly class ProductionStructureRigsValidator
{
    private const int MAX_RIGS = 3;

    private const string RIGS_PROPERTY_PATH = 'rigs';

    private const string MANUFACTURING_FAMILY = 'manufacturing';

    private const string REACTION_FAMILY = 'reaction';

    /** @var array<string, array{family: string, size: ?string}> */
    private const array FITTING_BY_STRUCTURE_TYPE = [
        'raitaru' => ['family' => self::MANUFACTURING_FAMILY, 'size' => 'M'],
        'azbel' => ['family' => self::MANUFACTURING_FAMILY, 'size' => 'L'],
        'sotiyo' => ['family' => self::MANUFACTURING_FAMILY, 'size' => 'XL'],
        'engineering_complex' => ['family' => self::MANUFACTURING_FAMILY, 'size' => null],
        'athanor' => ['family' => self::REACTION_FAMILY, 'size' => 'M'],
        'tatara' => ['family' => self::REACTION_FAMILY, 'size' => 'L'],
        'refinery' => ['family' => self::REACTION_FAMILY, 'size' => null],
    ];

    /** @param string[] $rigs */
    public function validate(string $structureType, array $rigs): void
    {
        $violations = new ConstraintViolationList();
        foreach ($this->violationMessages($structureType, $rigs) as $message) {
            $violations->add(new ConstraintViolation($message, $message, [], $rigs, self::RIGS_PROPERTY_PATH, $rigs));
        }

        if (count($violations) > 0) {
            throw new ValidationException($violations);
        }
    }

    /**
     * @param string[] $rigs
     *
     * @return list<string>
     */
    private function violationMessages(string $structureType, array $rigs): array
    {
        if ($rigs === []) {
            return [];
        }

        $fitting = self::FITTING_BY_STRUCTURE_TYPE[$structureType] ?? null;
        if ($fitting === null) {
            return [sprintf('A "%s" structure takes no rig.', $structureType)];
        }

        $messages = [];
        if (count($rigs) > self::MAX_RIGS) {
            $messages[] = sprintf('A production structure takes at most %d rigs.', self::MAX_RIGS);
        }

        foreach (array_unique(array_diff_assoc($rigs, array_unique($rigs))) as $duplicatedRig) {
            $messages[] = sprintf('The rig "%s" is fitted more than once.', $duplicatedRig);
        }

        $catalogue = $this->catalogueByName();
        foreach (array_unique($rigs) as $rig) {
            $catalogueRig = $catalogue[$rig] ?? null;
            if ($catalogueRig === null) {
                $messages[] = sprintf('The rig "%s" is not in the rig catalogue.', $rig);
                continue;
            }

            $fitsFamily = $catalogueRig['family'] === $fitting['family'];
            $fitsSize = $fitting['size'] === null || $catalogueRig['size'] === $fitting['size'];
            if (!$fitsFamily || !$fitsSize) {
                $messages[] = sprintf('The rig "%s" does not fit a "%s" structure.', $rig, $structureType);
            }
        }

        return $messages;
    }

    /** @return array<string, array{family: string, size: mixed}> */
    private function catalogueByName(): array
    {
        $catalogue = [];
        foreach (RigCatalogue::RIGS_BY_FAMILY as $family => $catalogueRigs) {
            foreach ($catalogueRigs as $catalogueRig) {
                $catalogue[(string) $catalogueRig['name']] = ['family' => $family, 'size' => $catalogueRig['size']];
            }
        }

        return $catalogue;
    }
}
