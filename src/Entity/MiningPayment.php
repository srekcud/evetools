<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningPaymentKind;
use Symfony\Component\Uid\Uuid;

class MiningPayment
{
    private ?Uuid $id = null;

    private ?\DateTimeImmutable $paidAt = null;

    private ?User $paidBy = null;

    private ?string $paidByName = null;

    public function __construct(
        private MiningOperation $operation,
        private int $position,
        private MiningPaymentKind $kind,
        private ?MiningOperationMember $payerMember,
        private ?string $payerName,
        private ?MiningOperationMember $beneficiaryMember,
        private ?string $beneficiaryName,
        private float $amount,
    ) {
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperation(): MiningOperation
    {
        return $this->operation;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getKind(): MiningPaymentKind
    {
        return $this->kind;
    }

    public function getPayerMember(): ?MiningOperationMember
    {
        return $this->payerMember;
    }

    public function getPayerName(): ?string
    {
        return $this->payerName;
    }

    public function getBeneficiaryMember(): ?MiningOperationMember
    {
        return $this->beneficiaryMember;
    }

    public function getBeneficiaryName(): ?string
    {
        return $this->beneficiaryName;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getPaidBy(): ?User
    {
        return $this->paidBy;
    }

    public function getPaidByName(): ?string
    {
        return $this->paidByName;
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function markPaid(User $paidBy, ?string $paidByName, \DateTimeImmutable $paidAt): void
    {
    }

    public function unmarkPaid(): void
    {
    }
}
