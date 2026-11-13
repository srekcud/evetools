<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectBomLine;
use App\Enum\LineMatchFailureReason;

final readonly class LineMatchFailure
{
    private function __construct(
        public int $typeId,
        public string $typeName,
        public LineMatchFailureReason $reason,
    ) {
    }

    public static function producerMissingFromSde(IndustryProjectBomLine $line): self
    {
        return new self($line->getTypeId(), $line->getTypeName(), LineMatchFailureReason::ProducerMissingFromSde);
    }

    public static function noRuns(IndustryProjectBomLine $line): self
    {
        return new self($line->getTypeId(), $line->getTypeName(), LineMatchFailureReason::NoRuns);
    }

    public static function contributionNotRefreshed(IndustryProjectBomLine $line): self
    {
        return new self($line->getTypeId(), $line->getTypeName(), LineMatchFailureReason::ContributionNotRefreshed);
    }

    /**
     * @return array{typeId: int, typeName: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'typeId' => $this->typeId,
            'typeName' => $this->typeName,
            'reason' => $this->reason->value,
        ];
    }

    /**
     * @return array{projectId: string, typeId: int, reason: string, error?: string}
     */
    public function logContext(string $projectId, ?string $error = null): array
    {
        $context = [
            'projectId' => $projectId,
            'typeId' => $this->typeId,
            'reason' => $this->reason->value,
        ];
        if ($error !== null) {
            $context['error'] = $error;
        }

        return $context;
    }
}
