<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidPlanetaryColonyLayoutException extends \DomainException
{
    /**
     * @param list<array{path: string, code: string}> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Invalid planetary colony layout');
    }

    /**
     * @return list<array{path: string, code: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
