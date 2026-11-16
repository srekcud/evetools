<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\Character;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1000')]
final class ProjectProducerCharactersApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private Character $secondOwnerCharacter;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->secondOwnerCharacter = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName('Brin Solberg')
            ->setCorporationId(self::OWNER_CORPORATION_ID)
            ->setCorporationName(sprintf('Corporation %d', self::OWNER_CORPORATION_ID));
        $this->owner->addCharacter($this->secondOwnerCharacter);
        $this->em->persist($this->secondOwnerCharacter);
        $this->em->flush();
    }

    public function testProjectReadsWithoutSelectionAsAllTheCharactersOfItsOwner(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->hecateBatch));

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseJson()['producerCharacterIds'] ?? null);
    }

    public function testOwnerChoosesTheProducerCharactersAndTheProjectReadsThemBack(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['producerCharacterIds' => [$this->secondOwnerCharacter->getEveCharacterId()]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->secondOwnerCharacter->getEveCharacterId()], $this->responseJson()['producerCharacterIds']);
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->hecateBatch));
        self::assertSame([$this->secondOwnerCharacter->getEveCharacterId()], $this->responseJson()['producerCharacterIds']);
    }

    public function testPatchWithoutTheSelectionKeepsTheChosenCharacters(): void
    {
        $this->hecateBatch->setProducerCharacterIds([$this->secondOwnerCharacter->getEveCharacterId()]);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['notes' => 'Keep Brin']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->secondOwnerCharacter->getEveCharacterId()], $this->responseJson()['producerCharacterIds']);
    }

    public function testEmptySelectionIsRejectedOnItsOwnField(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['producerCharacterIds' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['producerCharacterIds'], $this->violatedPaths());
    }

    public function testCharacterOfAnotherUserIsRejected(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['producerCharacterIds' => [$this->member->getMainCharacter()->getEveCharacterId()]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Unknown producer character', $this->errorDetail()[1]);
    }

    public function testCompletedProjectRefusesAChangeOfProducerCharacters(): void
    {
        $this->hecateBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['producerCharacterIds' => [$this->secondOwnerCharacter->getEveCharacterId()]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Cannot change the producer characters of a completed project', $this->errorDetail()[1]);
    }

    public function testAdministratorValidatesTheSelectionAgainstTheCharactersOfTheOwnerNotTheirOwn(): void
    {
        $this->addMembership($this->hecateBatch, $this->member, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['producerCharacterIds' => [$this->secondOwnerCharacter->getEveCharacterId()]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->secondOwnerCharacter->getEveCharacterId()], $this->responseJson()['producerCharacterIds']);
    }

    public function testPlanningOnlyHasTheLaneOfTheSelectedCharacter(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->hecateBatch).'/planning');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Aria Solberg', 'Brin Solberg'], $this->laneNames());

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['producerCharacterIds' => [$this->owner->getMainCharacter()->getEveCharacterId()]]);
        self::assertResponseStatusCodeSame(200);
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->hecateBatch).'/planning');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Aria Solberg'], $this->laneNames());
    }

    /**
     * @return list<string>
     */
    private function laneNames(): array
    {
        $names = array_column($this->responseJson()['characters'], 'characterName');
        sort($names);

        return $names;
    }
}
