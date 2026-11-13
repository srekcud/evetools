<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectBomLineMark;
use App\Entity\IndustryProjectJobMatch;
use App\Enum\BomLineMarkState;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-807')]
final class ProjectBomLineMarkPersistenceTest extends IntegrationTestCase
{
    private const int HAMMERHEAD_I = 2_183;
    private const string MARKED_AT = '2026-11-13 09:00:00';

    public function testStepMarkIsStoredWithItsLineAndItsRunsAreCountedOnceNextToALinkedJob(): void
    {
        $line = $this->persistedJobLine(10);
        $line->setMark(new IndustryProjectBomLineMark(BomLineMarkState::Done, 10, new \DateTimeImmutable(self::MARKED_AT)));
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(807_101)->setRuns(6)->setStatus('delivered'));
        $this->flushAndClear();

        $reloadedLine = $this->em->find(IndustryProjectBomLine::class, $line->getId());

        self::assertSame(
            ['done', 10, self::MARKED_AT, 6, 10],
            [
                $reloadedLine->getMark()?->getState()->value,
                $reloadedLine->getMark()?->getRuns(),
                $reloadedLine->getMark()?->getMarkedAt()->format('Y-m-d H:i:s'),
                $reloadedLine->getMatchedRuns(),
                $reloadedLine->getCountedRuns(),
            ],
        );
    }

    public function testRemovingTheStepMarkFromTheLineDeletesItsRow(): void
    {
        $line = $this->persistedJobLine(10);
        $line->setMark(new IndustryProjectBomLineMark(BomLineMarkState::Launched, 4, new \DateTimeImmutable(self::MARKED_AT)));
        $this->flushAndClear();

        $reloadedLine = $this->em->find(IndustryProjectBomLine::class, $line->getId());
        $reloadedLine->setMark(null);
        $this->flushAndClear();

        self::assertSame(0, $this->storedMarkCountOf($line));
    }

    public function testSecondStepMarkOnTheSameLineViolatesTheUniqueLine(): void
    {
        $line = $this->persistedJobLine(10);
        $line->setMark(new IndustryProjectBomLineMark(BomLineMarkState::Launched, 4, new \DateTimeImmutable(self::MARKED_AT)));
        $this->flushAndClear();

        $reloadedLine = $this->em->find(IndustryProjectBomLine::class, $line->getId());
        $duplicate = (new IndustryProjectBomLineMark(BomLineMarkState::Done, 5, new \DateTimeImmutable(self::MARKED_AT)))->setLine($reloadedLine);
        $this->em->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    public function testDeletingTheLineInTheDatabaseDeletesItsStepMark(): void
    {
        $line = $this->persistedJobLine(10);
        $line->setMark(new IndustryProjectBomLineMark(BomLineMarkState::Done, 10, new \DateTimeImmutable(self::MARKED_AT)));
        $this->flushAndClear();

        $this->em->getConnection()->executeStatement('DELETE FROM industry_project_bom_lines WHERE id = ?', [$line->getId()->toRfc4122()]);

        self::assertSame(0, $this->storedMarkCountOf($line));
    }

    private function persistedJobLine(int $runs): IndustryProjectBomLine
    {
        $owner = $this->createUser();
        $project = (new IndustryProject())->setOwner($owner)->setName('Hammerhead batch');
        $this->em->persist($project);
        $line = (new IndustryProjectBomLine())
            ->setTypeId(self::HAMMERHEAD_I)
            ->setTypeName('Hammerhead I')
            ->setRequiredQuantity($runs)
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setActivityType('manufacturing')
            ->setRuns($runs);
        $project->addBomItem($line);
        $this->em->persist($line);

        return $line;
    }

    private function storedMarkCountOf(IndustryProjectBomLine $line): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM industry_project_bom_line_marks WHERE line_id = ?', [$line->getId()->toRfc4122()]);
    }
}
