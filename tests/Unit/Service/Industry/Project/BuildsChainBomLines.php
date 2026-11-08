<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use Symfony\Component\Uid\Uuid;

trait BuildsChainBomLines
{
    private int $nextLineNumber = 1;

    private function jobLine(int $typeId, string $typeName, string $jobGroup, ?int $parentTypeId, int $runs = 10, string $activityType = 'manufacturing', ?int $meLevel = 10, ?int $teLevel = 20): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($runs)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setMeLevel($meLevel)
            ->setTeLevel($teLevel)
            ->setParentTypeId($parentTypeId);

        return $this->withLineId($line);
    }

    private function materialLine(int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setIsJob(false);

        return $this->withLineId($line);
    }

    private function withLineId(IndustryProjectBomLine $line): IndustryProjectBomLine
    {
        (new \ReflectionProperty(IndustryProjectBomLine::class, 'id'))
            ->setValue($line, Uuid::fromString(\sprintf('01970000-0000-7000-8000-%012d', $this->nextLineNumber++)));

        return $line;
    }

    private static function lineId(IndustryProjectBomLine $line): string
    {
        return $line->getId()->toRfc4122();
    }
}
