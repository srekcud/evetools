<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Overview;

final readonly class ProjectFlow
{
    public const string REASON_NOT_STARTED = 'not_started';
    public const string REASON_COMPLETED = 'completed';

    public function __construct(
        public ?int $toBring,
        public ?int $onSite,
        public ?int $inProduction,
        public ?int $out,
        public ?int $done,
        public int $unknown,
        public ?string $reason,
    ) {
    }

    /**
     * @return array{toBring: int, onSite: int, inProduction: int, out: int, done: int}|null
     */
    public function counts(): ?array
    {
        if (null === $this->toBring || null === $this->onSite || null === $this->inProduction || null === $this->out || null === $this->done) {
            return null;
        }

        return ['toBring' => $this->toBring, 'onSite' => $this->onSite, 'inProduction' => $this->inProduction, 'out' => $this->out, 'done' => $this->done];
    }

    public static function unavailable(string $reason): self
    {
        return new self(null, null, null, null, null, 0, $reason);
    }
}
