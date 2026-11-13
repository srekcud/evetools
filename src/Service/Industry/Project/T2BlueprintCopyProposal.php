<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class T2BlueprintCopyProposal
{
    public const string STATE_READY = 'ready';
    public const string STATE_NOT_SOLO = 'not_solo';
    public const string STATE_ASSETS_UNKNOWN = 'assets_unknown';

    /**
     * @param list<T2BlueprintCopyProposalLine> $lines
     */
    public function __construct(
        public string $state,
        public ?string $unknownReason,
        public array $lines,
    ) {
    }
}
