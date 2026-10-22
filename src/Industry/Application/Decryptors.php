<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Decryptor;

interface Decryptors
{
    /**
     * @return list<Decryptor>
     */
    public function all(): array;
}
