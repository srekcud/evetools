<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final class ColonyLayoutViolations
{
    /**
     * @param list<array{path: string, code: string}> $errors
     */
    public static function exception(object $subject, array $errors): ValidationException
    {
        $violations = new ConstraintViolationList();
        foreach ($errors as $error) {
            $violations->add(new ConstraintViolation($error['code'], $error['code'], [], $subject, $error['path'], null));
        }

        return new ValidationException($violations);
    }
}
