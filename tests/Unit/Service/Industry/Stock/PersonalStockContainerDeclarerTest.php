<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Stock;

use App\Entity\IndustryProject;
use App\Entity\PersonalStockContainer;
use App\Entity\User;
use App\Exception\ContainerDeclaredByAnotherUserException;
use App\Exception\ContainerNamedLikeProjectContainerException;
use App\Exception\ContainerNotVisibleException;
use App\Exception\NotAContainerException;
use App\Repository\IndustryProjectRepository;
use App\Repository\PersonalStockContainerRepository;
use App\Service\Industry\Stock\PersonalStockContainerDeclarer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalStockContainerDeclarer::class)]
#[Group('issue-1324')]
final class PersonalStockContainerDeclarerTest extends TestCase
{
    use StagesCorporationAssets;

    private const int STATION_JITA = 100;
    private const string PERSO_A = 'Perso A';
    private const int TRITANIUM = 34;

    private User $user;
    private int $officeAtJita;
    private MockObject&EntityManagerInterface $entityManager;
    private PersonalStockContainerRepository&Stub $declarations;
    private IndustryProjectRepository&Stub $projects;
    private PersonalStockContainerDeclarer $declarer;

    protected function setUp(): void
    {
        $this->stageCorporationAssets();
        $this->connectDirector();
        $this->user = $this->userInTheCorporation();
        $this->officeAtJita = $this->cacheOffice(self::STATION_JITA, 'Jita IV', 'Jita');

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->declarations = $this->createStub(PersonalStockContainerRepository::class);
        $this->projects = $this->createStub(IndustryProjectRepository::class);

        $this->declarer = new PersonalStockContainerDeclarer(
            $this->visibleAssetsFinder,
            $this->corporationAssetRepository,
            $this->declarations,
            $this->projects,
            $this->entityManager,
        );
    }

    public function testDeclaringAVisibleSingletonContainerTakesASnapshotOfItsNameDivisionAndStation(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $declaration = null;
        $this->entityManager->expects(self::once())->method('persist')->willReturnCallback(
            function (object $entity) use (&$declaration): void {
                $declaration = $entity;
            },
        );

        $declared = $this->declarer->declare($this->user, $persoA);

        self::assertSame($declaration, $declared);
        self::assertSame(
            [$this->user, $persoA, self::PERSO_A, 'Division 2', self::STATION_JITA, 'Jita IV'],
            [$declared->getUser(), $declared->getItemId(), $declared->getName(), $declared->getDivisionName(), $declared->getStationId(), $declared->getStationName()],
        );
    }

    public function testContainerWhoseStationIsUnresolvedIsStillDeclaredWithoutStation(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, 1_132_499_999_999);
        $this->entityManager->expects(self::once())->method('persist');

        $declared = $this->declarer->declare($this->user, $persoA);

        self::assertSame([self::PERSO_A, 'Division 2'], [$declared->getName(), $declared->getDivisionName()]);
        self::assertNull($declared->getStationId());
        self::assertNull($declared->getStationName());
    }

    public function testDeclaringTheSameContainerAgainReturnsMyExistingDeclarationWithoutPersistingAnother(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $existing = new PersonalStockContainer($this->user, $persoA, self::PERSO_A, 'Division 2', self::STATION_JITA, 'Jita IV');
        $this->declarations->method('findByItemId')->willReturn($existing);
        $this->entityManager->expects(self::never())->method('persist');

        $declared = $this->declarer->declare($this->user, $persoA);

        self::assertSame($existing, $declared);
    }

    public function testContainerOfAHiddenDivisionIsRefusedAsNotVisible(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita, 'CorpSAG5');
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(ContainerNotVisibleException::class);

        $this->declarer->declare($this->user, $persoA);
    }

    public function testUnknownItemIsRefusedAsNotVisible(): void
    {
        $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(ContainerNotVisibleException::class);

        $this->declarer->declare($this->user, 1_132_400_777_777);
    }

    public function testUserWithoutCorporationIsRefusedAsNotVisible(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(ContainerNotVisibleException::class);

        $this->declarer->declare(new User(), $persoA);
    }

    public function testNonSingletonAssetIsRefusedAsNotAContainer(): void
    {
        $stack = $this->cacheItem(self::TRITANIUM, 5, $this->officeAtJita, 'Tritanium', null, 'CorpSAG2');
        $this->assetOf($stack)->setIsSingleton(false);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(NotAContainerException::class);

        $this->declarer->declare($this->user, $stack);
    }

    public function testAssetWithoutSingletonFlagIsRefusedAsNotAContainer(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita, 'CorpSAG2', null);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(NotAContainerException::class);

        $this->declarer->declare($this->user, $persoA);
    }

    public function testContainerDeclaredByAnotherUserIsRefused(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->declarations->method('findByItemId')->willReturn(
            new PersonalStockContainer(new User(), $persoA, self::PERSO_A, 'Division 2', self::STATION_JITA, 'Jita IV'),
        );
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(ContainerDeclaredByAnotherUserException::class);

        $this->declarer->declare($this->user, $persoA);
    }

    public function testContainerNamedLikeAContainerOfAnOpenProjectOfMineIsRefused(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $project = (new IndustryProject())->setOwner($this->user);
        $project->addContainerAt(' perso A ', self::STATION_JITA);
        $this->projects->method('findOpenOwnedBy')->willReturn([$project]);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(ContainerNamedLikeProjectContainerException::class);

        $this->declarer->declare($this->user, $persoA);
    }

    public function testContainerNamedLikeAContainerOfAProjectIParticipateInIsRefused(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $project = (new IndustryProject())->setOwner(new User());
        $project->addContainerAt(self::PERSO_A, self::STATION_JITA);
        $this->projects->method('findOpenOfAcceptedParticipant')->willReturn([$project]);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(ContainerNamedLikeProjectContainerException::class);

        $this->declarer->declare($this->user, $persoA);
    }

    public function testContainerWithAnotherNameThanTheProjectContainersIsDeclared(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $project = (new IndustryProject())->setOwner($this->user);
        $project->addContainerAt('Ishtar A', self::STATION_JITA);
        $this->projects->method('findOpenOwnedBy')->willReturn([$project]);
        $this->entityManager->expects(self::once())->method('persist');

        $declared = $this->declarer->declare($this->user, $persoA);

        self::assertSame($persoA, $declared->getItemId());
    }
}
