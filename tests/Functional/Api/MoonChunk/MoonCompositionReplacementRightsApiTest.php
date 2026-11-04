<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonChunk;

use App\Entity\Character;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Service\ESI\CharacterService;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-368')]
final class MoonCompositionReplacementRightsApiTest extends WebTestCase
{
    use DrivesMoonChunkApi;

    private const string ENDPOINT = '/api/mining/moon-compositions';
    private const string SCAN = "Bitumens\t50 %\nChromite\t0,5";

    private User $cole;

    /** @var list<int> */
    private array $rolesReadFor = [];

    protected function setUp(): void
    {
        $this->seedMoonChunkWorld();
        $this->cole = $this->persistPilotOf('Cole Reyes', self::ILYRA_CORPORATION_ID);
        $this->em->flush();
    }

    public function testAMemberPastesTheFirstCompositionOfAMoonWithoutAnyRoleCheck(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM moon_compositions');
        $this->withRolesOfCole(['Accountant']);
        $this->as($this->cole);

        $this->request('POST', self::ENDPOINT, $this->payload());

        self::assertSame([201, $this->cole->getId()?->toRfc4122(), []], [$this->client->getResponse()->getStatusCode(), $this->recordedByKnownValueMoon(), $this->rolesReadFor]);
    }

    public function testAnotherMemberWithoutAManagementRoleCannotReplaceTheCompositionOfTheAuthor(): void
    {
        $this->withRolesOfCole(['Accountant']);
        $this->as($this->cole);

        $this->request('POST', self::ENDPOINT, $this->payload());

        self::assertSame([403, $this->aria->getId()?->toRfc4122(), $this->coleEveCharacterIds()], [$this->client->getResponse()->getStatusCode(), $this->recordedByKnownValueMoon(), $this->rolesReadFor]);
    }

    public function testADirectorReplacesTheCompositionOfTheAuthor(): void
    {
        $this->withRolesOfCole(['Director']);
        $this->as($this->cole);

        $this->request('POST', self::ENDPOINT, $this->payload());

        self::assertSame([201, $this->cole->getId()?->toRfc4122(), $this->coleEveCharacterIds()], [$this->client->getResponse()->getStatusCode(), $this->recordedByKnownValueMoon(), $this->rolesReadFor]);
    }

    public function testTheAuthorReplacesHisCompositionWithoutAnyRoleCheck(): void
    {
        $this->withRolesOfCole(['Accountant']);
        $this->as($this->aria);

        $this->request('POST', self::ENDPOINT, $this->payload());

        self::assertSame([201, []], [$this->client->getResponse()->getStatusCode(), $this->rolesReadFor]);
    }

    public function testUnreadableRolesAreAServiceErrorAndLeaveTheCompositionUntouched(): void
    {
        $this->withRolesOfCole(new EsiApiException('ESI server error', 503));
        $this->as($this->cole);

        $this->request('POST', self::ENDPOINT, $this->payload());

        self::assertSame([503, $this->aria->getId()?->toRfc4122()], [$this->client->getResponse()->getStatusCode(), $this->recordedByKnownValueMoon()]);
    }

    public function testAMemberWithoutExtractionOnTheMoonStillGetsNotFoundAndNotForbidden(): void
    {
        $this->withRolesOfCole([]);
        $this->as($this->cole);

        $this->request('POST', self::ENDPOINT, ['corporationId' => self::ILYRA_CORPORATION_ID, 'moonId' => self::MOON_WITHOUT_EXTRACTION_ID, 'scan' => self::SCAN]);

        self::assertSame([404, []], [$this->client->getResponse()->getStatusCode(), $this->rolesReadFor]);
    }

    /**
     * @param list<string>|\Throwable $roles
     */
    private function withRolesOfCole(array|\Throwable $roles): void
    {
        $stub = $this->createStub(CharacterService::class);
        $stub->method('getCharacterRoles')->willReturnCallback(function (Character $character) use ($roles): array {
            $this->rolesReadFor[] = $character->getEveCharacterId();
            if ($roles instanceof \Throwable) {
                throw $roles;
            }

            return $roles;
        });
        $this->characterServiceStub = $stub;
    }

    /**
     * @return list<int>
     */
    private function coleEveCharacterIds(): array
    {
        return $this->cole->getCharacters()->map(static fn (Character $character): int => $character->getEveCharacterId())->getValues();
    }

    private function recordedByKnownValueMoon(): ?string
    {
        $this->em->clear();
        $composition = $this->em->getRepository(MoonComposition::class)->findOneBy(['corporationId' => self::ILYRA_CORPORATION_ID, 'moonId' => self::KNOWN_VALUE_MOON_ID]);

        return $composition?->getRecordedBy()?->getId()?->toRfc4122();
    }

    private function payload(): array
    {
        return ['corporationId' => self::ILYRA_CORPORATION_ID, 'moonId' => self::KNOWN_VALUE_MOON_ID, 'scan' => self::SCAN];
    }
}
