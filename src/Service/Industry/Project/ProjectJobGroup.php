<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;

final readonly class ProjectJobGroup
{
    /**
     * @param non-empty-list<IndustryProjectJobMatch> $matches
     */
    private function __construct(private array $matches)
    {
    }

    /**
     * @return list<IndustryProjectJobMatch>
     */
    public static function linksOf(IndustryProject $project): array
    {
        $links = [];
        foreach ($project->getBomItems() as $line) {
            foreach ($line->getJobMatches() as $link) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * @param list<IndustryProjectJobMatch> $links
     *
     * @return list<self>
     */
    public static function groupsOf(array $links): array
    {
        $linksByInstallerAndLine = [];
        foreach ($links as $link) {
            $key = $link->getBomLine()->getId()->toRfc4122().'|'.$link->getCharacterName();
            $linksByInstallerAndLine[$key][] = $link;
        }

        return array_values(array_map(static fn (array $grouped): self => new self($grouped), $linksByInstallerAndLine));
    }

    public function installerName(): ?string
    {
        return $this->matches[0]->getCharacterName();
    }

    public function typeId(): int
    {
        return $this->matches[0]->getBomLine()->getTypeId();
    }

    public function typeName(): string
    {
        return $this->matches[0]->getBomLine()->getTypeName();
    }

    public function jobCount(): int
    {
        return \count($this->matches);
    }

    public function runs(): int
    {
        return array_sum(array_map(static fn (IndustryProjectJobMatch $link): int => $link->getRuns(), $this->matches));
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        $starts = array_map(static fn (IndustryProjectJobMatch $link): ?\DateTimeImmutable => $link->getStartDate(), $this->matches);
        if (\in_array(null, $starts, true)) {
            return null;
        }

        return min($starts);
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        $ends = array_filter(array_map(static fn (IndustryProjectJobMatch $link): ?\DateTimeImmutable => $link->getEndDate(), $this->matches));

        return [] === $ends ? null : max($ends);
    }

    public function cost(): ?float
    {
        $costs = array_map(static fn (IndustryProjectJobMatch $link): ?float => $link->getCost(), $this->matches);
        if (\in_array(null, $costs, true)) {
            return null;
        }

        return array_sum($costs);
    }

    /**
     * @return list<int>
     */
    public function jobIds(): array
    {
        return array_map(static fn (IndustryProjectJobMatch $link): int => $link->getEsiJobId(), $this->matches);
    }
}
