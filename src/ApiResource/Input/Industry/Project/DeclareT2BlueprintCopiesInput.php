<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class DeclareT2BlueprintCopiesInput
{
    public const int MAXIMUM_COPIES = 200;

    /** @var list<DeclaredT2BlueprintCopyInput>|null */
    #[Assert\NotNull]
    #[Assert\Count(max: self::MAXIMUM_COPIES)]
    #[Assert\All([new Assert\Type(DeclaredT2BlueprintCopyInput::class)])]
    #[Assert\Valid]
    public ?array $copies = null;

    /** @param array<array-key, mixed>|null $copies */
    public function setCopies(?array $copies): void
    {
        $this->copies = null === $copies ? null : array_values(array_map(
            static fn (mixed $copy): mixed => \is_array($copy) ? DeclaredT2BlueprintCopyInput::fromArray($copy) : $copy,
            $copies,
        ));
    }
}
