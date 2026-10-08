<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * An amount that is either known or unknown, never 0 by default (spec R10, glossary "Coût inconnu").
 */
final readonly class Cost
{
    /**
     * @param list<MissingData> $missingData
     */
    private function __construct(private ?Isk $knownAmount, public array $missingData)
    {
    }

    public static function known(Isk $amount): self
    {
        return new self($amount, []);
    }

    public static function unknown(MissingData $missingData, MissingData ...$otherMissingData): self
    {
        return new self(null, self::withoutDuplicates([$missingData, ...$otherMissingData]));
    }

    public function isKnown(): bool
    {
        return null !== $this->knownAmount;
    }

    public function amount(): Isk
    {
        if (null === $this->knownAmount) {
            throw new \LogicException('An unknown cost has no amount: check isKnown() first.');
        }

        return $this->knownAmount;
    }

    /**
     * A sum containing an unknown cost is unknown and lists every missing data once, in order of appearance.
     */
    public function plus(self $other): self
    {
        if (null !== $this->knownAmount && null !== $other->knownAmount) {
            return self::known(new Isk($this->knownAmount->amount + $other->knownAmount->amount));
        }

        return new self(null, self::withoutDuplicates([...$this->missingData, ...$other->missingData]));
    }

    /**
     * A share of an unknown cost stays unknown, with the same missing data.
     */
    public function times(float $factor): self
    {
        if (null === $this->knownAmount) {
            return $this;
        }

        return self::known(new Isk($this->knownAmount->amount * $factor));
    }

    /**
     * @param array<MissingData> $missingData
     *
     * @return list<MissingData>
     */
    private static function withoutDuplicates(array $missingData): array
    {
        $distinct = [];
        foreach ($missingData as $candidate) {
            // Loose comparison: two MissingData with the same reason and typeId are the same missing data.
            if (!\in_array($candidate, $distinct, false)) {
                $distinct[] = $candidate;
            }
        }

        return $distinct;
    }
}
