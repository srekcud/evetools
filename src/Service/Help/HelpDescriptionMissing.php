<?php

declare(strict_types=1);

namespace App\Service\Help;

final class HelpDescriptionMissing extends \LogicException
{
    public static function forKey(string $key, string $language): self
    {
        return new self(\sprintf('No %s help description for "%s"', $language, $key));
    }
}
