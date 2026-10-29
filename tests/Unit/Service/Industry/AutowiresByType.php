<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

trait AutowiresByType
{
    private static function autowired(string $class, array $services): object
    {
        $parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];

        return new $class(...array_map(
            static function (\ReflectionParameter $parameter) use ($class, $services): object {
                $type = (string) $parameter->getType();
                foreach ($services as $service) {
                    if ($service instanceof $type) {
                        return $service;
                    }
                }
                if (str_starts_with($type, 'App\\Service\\Industry\\') && class_exists($type)) {
                    return self::autowired($type, $services);
                }

                throw new \LogicException(\sprintf('The harness has no %s for $%s of %s', $type, $parameter->getName(), $class));
            },
            $parameters,
        ));
    }
}
