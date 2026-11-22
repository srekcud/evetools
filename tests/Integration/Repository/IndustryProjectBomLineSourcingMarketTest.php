<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Enum\SourcingMarket;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1287')]
final class IndustryProjectBomLineSourcingMarketTest extends IntegrationTestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testNewLineHasNoStoredSourcingMarketAndTheColumnIsNull(): void
    {
        $line = $this->persistedMaterialLine($this->persistedProject(), self::TRITANIUM);

        self::assertNull($line->getSourcingMarket());
        self::assertNull($this->storedSourcingMarketOf($line));
    }

    public function testSourcingMarketIsStoredAsTheValueOfTheSideAndReadBackAsTheSide(): void
    {
        $structureLine = $this->persistedMaterialLine($this->persistedProject(), self::TRITANIUM)->setSourcingMarket(SourcingMarket::Structure);
        $jitaLine = $this->persistedMaterialLine($this->persistedProject(), self::PYERITE)->setSourcingMarket(SourcingMarket::Jita);
        $untouchedLine = $this->persistedMaterialLine($this->persistedProject(), self::MEXALLON);
        $this->flushAndClear();

        self::assertSame(
            ['structure', 'jita', null],
            [$this->storedSourcingMarketOf($structureLine), $this->storedSourcingMarketOf($jitaLine), $this->storedSourcingMarketOf($untouchedLine)],
        );
        self::assertSame(
            [SourcingMarket::Structure, SourcingMarket::Jita, null],
            array_map(
                fn (IndustryProjectBomLine $line): ?SourcingMarket => $this->em->find(IndustryProjectBomLine::class, $line->getId())->getSourcingMarket(),
                [$structureLine, $jitaLine, $untouchedLine],
            ),
        );
    }

    public function testClearingTheSourcingMarketStoresNullAgain(): void
    {
        $line = $this->persistedMaterialLine($this->persistedProject(), self::TRITANIUM)->setSourcingMarket(SourcingMarket::Structure);
        $this->flushAndClear();

        $this->em->find(IndustryProjectBomLine::class, $line->getId())->setSourcingMarket(null);
        $this->flushAndClear();

        self::assertNull($this->storedSourcingMarketOf($line));
    }

    private function persistedMaterialLine(IndustryProject $project, int $typeId): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material '.$typeId)
            ->setRequiredQuantity(100 + $typeId)
            ->setIsJob(false);
        $project->addBomItem($line);
        $this->em->flush();

        return $line;
    }

    private function persistedProject(): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->createUser())->setName('Sourcing market run');
        $this->em->persist($project);

        return $project;
    }

    private function storedSourcingMarketOf(IndustryProjectBomLine $line): ?string
    {
        $stored = $this->connection->fetchOne('SELECT sourcing_market FROM industry_project_bom_lines WHERE id = ?', [$line->getId()->toRfc4122()]);

        return false === $stored ? throw new \LogicException('The line is not stored.') : $stored;
    }
}
