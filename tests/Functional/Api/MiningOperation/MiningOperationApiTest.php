<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-262')]
final class MiningOperationApiTest extends WebTestCase
{
    use PersistsMiningOperations;

    private const string SPODUMAIN_LABEL = 'Anomalie Spodumain — 20:00';
    private const string PLANNED_START = '2026-11-05T20:00:00+00:00';
    private const string JOIN_CODE = '7f3a9c2e1b';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private User $dorn;
    private MiningOperation $spodumain;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II']);
        $this->dorn = $this->persistMiningPilot($this->em, 'Dorn Achar', null);
        $this->spodumain = $this->persistMiningOperation($this->em, $this->aria, self::SPODUMAIN_LABEL, self::PLANNED_START, ownerCharacterNames: ['Aria Solberg']);
        $this->spodumain->setJoinCode(self::JOIN_CODE);
        $this->em->flush();
    }

    public function testAriaCreatesTheSpodumainOperation(): void
    {
        $this->client->loginUser($this->aria, 'api');

        $this->request('POST', '/api/mining-operations', $this->creation(self::PLANNED_START, ['Aria II']));

        self::assertResponseStatusCodeSame(201);
        $created = $this->responseJson();
        self::assertSame(
            ['planned', 'corporation', 'value_prorata', 98262101, 'owner', 'accepted', 1, 1, 'Aria Solberg', self::PLANNED_START],
            [
                $created['status'] ?? null,
                $created['visibility'] ?? null,
                $created['splitMode'] ?? null,
                $created['corporationId'] ?? null,
                $created['myRole'] ?? null,
                $created['myStatus'] ?? null,
                $created['participantsCount'] ?? null,
                $created['charactersCount'] ?? null,
                $created['ownerCharacterName'] ?? null,
                $created['plannedStartAt'] ?? null,
            ],
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', (string) ($created['joinCode'] ?? ''));
    }

    public function testPlannedStartSentWithAnOffsetIsReturnedInEveTime(): void
    {
        $this->client->loginUser($this->aria, 'api');

        $this->request('POST', '/api/mining-operations', $this->creation('2026-11-05T21:00:00+01:00', ['Aria II']));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::PLANNED_START, $this->responseJson()['plannedStartAt'] ?? null);
    }

    public function testBlankLabelIsRejectedOnTheLabelAndNoOperationIsCreated(): void
    {
        $this->client->loginUser($this->aria, 'api');

        $this->request('POST', '/api/mining-operations', ['label' => '   '] + $this->creation(self::PLANNED_START, ['Aria II']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['label'], $this->violatedPaths());
        self::assertSame(1, $this->operationsOwnedBy($this->aria));
    }

    public function testInvalidJoinInputIsRejectedBeforeTheCodeIsLookedUp(): void
    {
        $this->client->loginUser($this->brann, 'api');

        $this->request('POST', '/api/mining-operations/join/ffffffffff', ['characterIds' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['characterIds'], $this->violatedPaths());
    }

    public function testUnknownJoinCodeIsNotFound(): void
    {
        $this->client->loginUser($this->brann, 'api');

        $this->request('GET', '/api/mining-operations/join/ffffffffff');

        self::assertSame([404, 'Mining operation not found'], $this->errorDetail());
    }

    public function testOperationIdThatIsNotAUuidIsNotFound(): void
    {
        $this->client->loginUser($this->aria, 'api');

        $this->request('GET', '/api/mining-operations/abc');

        self::assertSame([404, 'Mining operation not found'], $this->errorDetail());
    }

    public function testAnonymousVisitorCannotListMiningOperations(): void
    {
        $this->request('GET', '/api/mining-operations');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAvailableOperationsAreNotTakenForAnOperationIdentifier(): void
    {
        $this->client->loginUser($this->dorn, 'api');

        $this->request('GET', '/api/mining-operations/available');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseJson());
    }

    public function testBrannJoinsWithTheRenewedCodeButNoLongerWithThePreviousOne(): void
    {
        $this->client->loginUser($this->aria, 'api');
        $this->request('POST', sprintf('/api/mining-operations/%s/join-code', $this->spodumain->getId()), []);
        self::assertResponseStatusCodeSame(200);
        $renewedJoinCode = (string) ($this->responseJson()['joinCode'] ?? '');

        $this->client->loginUser($this->brann, 'api');
        $this->request('POST', '/api/mining-operations/join/' . self::JOIN_CODE, ['characterIds' => [$this->characterId('Brann Holt')]]);
        self::assertSame([404, 'Mining operation not found'], $this->errorDetail());

        $this->request('POST', '/api/mining-operations/join/' . $renewedJoinCode, ['characterIds' => [$this->characterId('Brann Holt')]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('accepted', $this->responseJson()['myStatus'] ?? null);
    }

    public function testOwnerDeletesThePlannedOperationWithItsMembersAndEnrolledCharacters(): void
    {
        $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->em->flush();
        $operationId = (string) $this->spodumain->getId();
        $this->client->loginUser($this->aria, 'api');

        $this->request('DELETE', '/api/mining-operations/' . $operationId);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(
            ['operation' => 0, 'members' => 0, 'enrolled characters' => 0],
            [
                'operation' => $this->countWhereIn('mining_operations', 'id', [$operationId]),
                'members' => $this->countWhereIn('mining_operation_members', 'operation_id', [$operationId]),
                'enrolled characters' => $this->countWhereIn('mining_operation_characters', 'character_id', [$this->characterId('Brann Holt'), $this->characterId('Aria Solberg')]),
            ],
        );
    }

    public function testAcceptedMemberLeavesThePlannedOperation(): void
    {
        $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->em->flush();
        $this->client->loginUser($this->brann, 'api');

        $this->request('POST', sprintf('/api/mining-operations/%s/leave', $this->spodumain->getId()), []);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->countWhereIn('mining_operation_characters', 'character_id', [$this->characterId('Brann Holt')]));
    }

    public function testOwnerRefusesAPendingRequest(): void
    {
        $brannMembership = $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Brann Holt']);
        $this->em->flush();
        $this->client->loginUser($this->aria, 'api');

        $this->request('DELETE', sprintf('/api/mining-operations/%s/members/%s', $this->spodumain->getId(), $brannMembership->getId()));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->countWhereIn('mining_operation_characters', 'character_id', [$this->characterId('Brann Holt')]));
    }

    private function creation(string $plannedStartAt, array $characterNames): array
    {
        return [
            'label' => self::SPODUMAIN_LABEL,
            'plannedStartAt' => $plannedStartAt,
            'visibility' => 'corporation',
            'splitMode' => 'value_prorata',
            'characterIds' => array_map(fn (string $name): string => $this->characterId($name), $characterNames),
        ];
    }

    private function characterId(string $name): string
    {
        return (string) $this->miningCharacters[$name]->getId();
    }

    private function operationsOwnedBy(User $owner): int
    {
        return $this->countWhereIn('mining_operations', 'owner_id', [(string) $owner->getId()]);
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? null, $error['detail'] ?? null];
    }

    private function violatedPaths(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $path): string => (string) preg_replace('/\[.*$/', '', $path),
            array_column($this->responseJson()['violations'] ?? [], 'propertyPath'),
        )));
    }

    private function countWhereIn(string $table, string $column, array $ids): int
    {
        $connection = $this->em->getConnection();

        return (int) $connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s::text IN (?)', $connection->quoteSingleIdentifier($table), $connection->quoteSingleIdentifier($column)),
            [$ids],
            [ArrayParameterType::STRING],
        );
    }
}
