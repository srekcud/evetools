<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\MiningOperation;

use Symfony\Component\Validator\Validation;

trait ViolatedFieldsOf
{
    private function violatedFieldsOf(object $input): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $fields = [];
        foreach ($validator->validate($input) as $violation) {
            $fields[] = (string) preg_replace('/\[.*$/', '', $violation->getPropertyPath());
        }

        return array_values(array_unique($fields));
    }
}
