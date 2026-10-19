<?php

declare(strict_types=1);

namespace App\Entity;

class IndustryStockpileImport
{
    private \DateTimeImmutable $createdAt;

    public function __construct(
        private User $user,
        private int $productTypeId,
        private int $runs,
        private int $me,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProductTypeId(): int
    {
        return $this->productTypeId;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function getMe(): int
    {
        return $this->me;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
