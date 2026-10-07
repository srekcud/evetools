<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\State\Processor\Industry\CreateStructureProcessor;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #76: importing an ESI structure (known locationId) the user already has active is refused
 * with a 409 instead of creating a second row. The existing structure is left untouched.
 * Manual structures (no locationId) are not concerned, and re-importing a soft-deleted
 * structure still restores it (#36).
 */
final class DuplicateStructureImportTest extends IntegrationTestCase
{
    private const int RAITARU_LOCATION_ID = 1_035_466_617_946;

    private User $pilot;

    protected function setUp(): void
    {
        parent::setUp();
        $character = $this->createCharacter(name: 'Industry Pilot');
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->em->flush();

        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($this->pilot, 'api', $this->pilot->getRoles()));
    }

    public function testSecondImportOfAnActiveStructureIsRefusedWithAConflict(): void
    {
        $this->create($this->importInput('Favorite Raitaru'));

        $this->expectException(ConflictHttpException::class);

        $this->create($this->importInput('Favorite Raitaru'));
    }

    public function testRefusedSecondImportLeavesASingleUntouchedStructure(): void
    {
        $first = $this->importInput('Favorite Raitaru');
        $first->rigs = ['Standup M-Set Equipment Manufacturing Material Efficiency I'];
        $first->isDefault = true;
        $this->create($first);

        $second = $this->importInput('Renamed Raitaru');
        $second->structureType = 'azbel';
        $second->rigs = [];
        $second->isDefault = true;
        try {
            $this->create($second);
            self::fail('A second import of an active structure must be refused.');
        } catch (ConflictHttpException) {
        }

        $this->em->clear();
        $rows = $this->structureRepository()->findBy(['user' => $this->pilot, 'locationId' => self::RAITARU_LOCATION_ID]);
        self::assertCount(1, $rows);
        self::assertSame('Favorite Raitaru', $rows[0]->getName());
        self::assertSame('raitaru', $rows[0]->getStructureType());
        self::assertSame(['Standup M-Set Equipment Manufacturing Material Efficiency I'], $rows[0]->getRigs());
        // The refused import must not clear the default flag of the existing structure either
        self::assertTrue($rows[0]->isDefault());
    }

    public function testConflictMessageNamesTheAlreadyImportedStructure(): void
    {
        $this->create($this->importInput('Favorite Raitaru'));

        try {
            $this->create($this->importInput('Favorite Raitaru'));
            self::fail('A second import of an active structure must be refused.');
        } catch (ConflictHttpException $conflict) {
            self::assertSame(409, $conflict->getStatusCode());
            self::assertStringContainsString('Favorite Raitaru', $conflict->getMessage());
            self::assertStringContainsString('already', $conflict->getMessage());
        }
    }

    public function testTwoManualStructuresWithTheSameNameAreAllowed(): void
    {
        $this->create($this->manualInput('Home Raitaru'));
        $this->create($this->manualInput('Home Raitaru'));

        $this->em->clear();
        $rows = $this->structureRepository()->findBy(['user' => $this->pilot, 'name' => 'Home Raitaru', 'isDeleted' => false]);
        self::assertCount(2, $rows);
    }

    public function testImportAfterDeletionRestoresTheDeletedStructure(): void
    {
        $deleted = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName('Favorite Raitaru')
            ->setLocationId(self::RAITARU_LOCATION_ID)
            ->setSecurityType('nullsec')
            ->setStructureType('raitaru')
            ->setRigs([])
            ->hideForUser($this->userIdOf($this->pilot))
            ->setIsDeleted(true);
        $this->em->persist($deleted);
        $this->em->flush();
        $deletedId = $deleted->getId()->toRfc4122();

        $this->create($this->importInput('Restored Raitaru'));

        $this->em->clear();
        $rows = $this->structureRepository()->findBy(['user' => $this->pilot, 'locationId' => self::RAITARU_LOCATION_ID]);
        self::assertCount(1, $rows);
        self::assertSame($deletedId, $rows[0]->getId()->toRfc4122());
        self::assertFalse($rows[0]->isDeleted());
        self::assertSame('Restored Raitaru', $rows[0]->getName());
    }

    private function importInput(string $name): CreateStructureInput
    {
        // Payload the frontend sends after picking a structure in the ESI search or the corporation list
        $input = new CreateStructureInput();
        $input->name = $name;
        $input->locationId = self::RAITARU_LOCATION_ID;
        $input->securityType = 'nullsec';
        $input->structureType = 'raitaru';
        $input->rigs = [];

        return $input;
    }

    private function manualInput(string $name): CreateStructureInput
    {
        $input = new CreateStructureInput();
        $input->name = $name;
        $input->securityType = 'lowsec';
        $input->structureType = 'raitaru';
        $input->rigs = [];

        return $input;
    }

    private function create(CreateStructureInput $input): void
    {
        self::getContainer()->get(CreateStructureProcessor::class)->process($input, new Post());
    }

    private function structureRepository(): IndustryStructureConfigRepository
    {
        return self::getContainer()->get(IndustryStructureConfigRepository::class);
    }

    private function userIdOf(User $user): string
    {
        $id = $user->getId();
        \assert($id instanceof Uuid);

        return $id->toRfc4122();
    }
}
