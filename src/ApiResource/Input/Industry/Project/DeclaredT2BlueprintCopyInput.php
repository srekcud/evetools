<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class DeclaredT2BlueprintCopyInput
{
    public const int MAXIMUM_INTEGER = 2_147_483_647;

    #[Assert\NotNull]
    #[Assert\LessThanOrEqual(self::MAXIMUM_INTEGER)]
    public ?int $productTypeId = null;

    #[Assert\NotNull]
    #[Assert\LessThanOrEqual(self::MAXIMUM_INTEGER)]
    public ?int $runs = null;

    /** @param array<array-key, mixed> $values */
    public static function fromArray(array $values): self
    {
        $input = new self();
        $input->productTypeId = self::integerAt($values, 'productTypeId');
        $input->runs = self::integerAt($values, 'runs');

        return $input;
    }

    /** @param array<array-key, mixed> $values */
    private static function integerAt(array $values, string $key): ?int
    {
        return \array_key_exists($key, $values) && \is_int($values[$key]) ? $values[$key] : null;
    }
}
