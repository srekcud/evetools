<?php

declare(strict_types=1);

namespace App\Exception;

final class PlanetSchematicIncompleteException extends \RuntimeException
{
    public function __construct(int $schematicId)
    {
        parent::__construct(sprintf('Planet schematic %d is incomplete in the SDE', $schematicId));
    }
}
