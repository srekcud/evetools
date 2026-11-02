<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;

final class SubResourceUriVariablesDetector
{
    private const string URI_VARIABLE_PATTERN = '/\{(\w+)(?:<[^>]*>)?\}/';

    public function isSubResource(HttpOperation $operation): bool
    {
        $uriTemplate = (string) $operation->getUriTemplate();
        preg_match_all(self::URI_VARIABLE_PATTERN, $uriTemplate, $matches, \PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$placeholder, $offset]) {
            if ('/' === substr($uriTemplate, $offset + \strlen($placeholder), 1)) {
                return true;
            }
        }

        return false;
    }

    public function undeclaredUriVariables(HttpOperation $operation): array
    {
        if (!$this->isSubResource($operation)) {
            return [];
        }

        $declaredUriVariables = $operation->getUriVariables();
        $declaredUriVariables = \is_array($declaredUriVariables) ? $declaredUriVariables : [];
        preg_match_all(self::URI_VARIABLE_PATTERN, (string) $operation->getUriTemplate(), $matches);

        return array_values(array_filter(
            $matches[1],
            static fn (string $name): bool => !(($declaredUriVariables[$name] ?? null) instanceof Link),
        ));
    }
}
