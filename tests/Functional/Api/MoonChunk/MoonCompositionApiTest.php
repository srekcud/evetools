<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonChunk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-308')]
final class MoonCompositionApiTest extends WebTestCase
{
    use DrivesMoonChunkApi;

    private const string ENDPOINT = '/api/mining/moon-compositions';
    private const int MAX_SCAN_LENGTH = 5_000;

    protected function setUp(): void
    {
        $this->seedMoonChunkWorld();
        $this->em->getConnection()->executeStatement('DELETE FROM moon_compositions');
    }

    public function testAMemberOfTheCorporationRecordsTheCompositionOfAMoonWithAnExtraction(): void
    {
        $this->as($this->aria);

        $this->request('POST', self::ENDPOINT, $this->payload(self::ILYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID, "Bitumens\t50 %\nChromite\t0,5"));

        $body = $this->responseJson();
        self::assertSame(
            [
                201,
                self::ILYRA_CORPORATION_ID,
                self::KNOWN_VALUE_MOON_ID,
                '19:00:00',
                [[self::BITUMENS, 'Bitumens', 0.5], [self::CHROMITE, 'Chromite', 0.5]],
                1,
            ],
            [
                $this->client->getResponse()->getStatusCode(),
                $body['corporationId'] ?? null,
                $body['moonId'] ?? null,
                isset($body['recordedAt']) ? (new \DateTimeImmutable($body['recordedAt']))->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s') : null,
                array_map(static fn (array $line): array => [$line['typeId'] ?? null, $line['typeName'] ?? null, $line['share'] ?? null], $body['lines'] ?? []),
                $this->storedCompositionCount(),
            ],
        );
    }

    public static function requestsWithoutRightOnTheMoon(): iterable
    {
        yield 'moon without extraction in the corporation of the user' => [self::ILYRA_CORPORATION_ID, self::MOON_WITHOUT_EXTRACTION_ID];
        yield 'moon extracted only by another corporation' => [self::ILYRA_CORPORATION_ID, self::CYRA_ONLY_MOON_ID];
        yield 'corporation of which the user has no character' => [self::CYRA_CORPORATION_ID, self::CYRA_ONLY_MOON_ID];
        yield 'identifiers at the integer bound' => [PHP_INT_MAX, PHP_INT_MAX];
    }

    #[DataProvider('requestsWithoutRightOnTheMoon')]
    public function testARequestWithoutRightOnTheMoonGetsTheSameNotFoundAndStoresNothing(int $corporationId, int $moonId): void
    {
        $this->as($this->aria);
        $this->request('POST', self::ENDPOINT, $this->payload(self::ILYRA_CORPORATION_ID, self::MOON_WITHOUT_EXTRACTION_ID, "Bitumens\t100 %"));
        $reference = $this->errorDetail();

        $this->request('POST', self::ENDPOINT, $this->payload($corporationId, $moonId, "Bitumens\t100 %"));

        self::assertSame([[404, $reference[1]], 0], [$this->errorDetail(), $this->storedCompositionCount()]);
    }

    public function testAScanOfMoreThanOneHundredLinesIsRefusedAsABadRequest(): void
    {
        $this->as($this->aria);

        $this->request('POST', self::ENDPOINT, $this->payload(self::ILYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID, implode("\n", array_fill(0, 101, "Bitumens\t0.01"))));

        self::assertSame([400, 0], [$this->client->getResponse()->getStatusCode(), $this->storedCompositionCount()]);
    }

    public function testAScanIsAcceptedAtFiveThousandCharactersAndRefusedAsABadRequestOneCharacterBeyond(): void
    {
        $this->as($this->aria);
        $scan = "Bitumens\t100 %";

        $this->request('POST', self::ENDPOINT, $this->payload(self::ILYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID, $scan.str_repeat(' ', self::MAX_SCAN_LENGTH - strlen($scan))));
        $atTheLimit = $this->client->getResponse()->getStatusCode();
        $this->request('POST', self::ENDPOINT, $this->payload(self::ILYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID, $scan.str_repeat(' ', self::MAX_SCAN_LENGTH + 1 - strlen($scan))));

        self::assertSame([201, 400], [$atTheLimit, $this->client->getResponse()->getStatusCode()]);
    }

    public function testARejectedScanIsAValidationErrorNamingTheFaultyLineAndStoresNothing(): void
    {
        $this->as($this->aria);

        $this->request('POST', self::ENDPOINT, $this->payload(self::ILYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID, "Bitumens\t50 %\nUnobtainium\t50 %"));

        $paths = array_column($this->responseJson()['violations'] ?? [], 'propertyPath');
        self::assertSame([422, ['scan[2]'], 0], [$this->client->getResponse()->getStatusCode(), $paths, $this->storedCompositionCount()]);
    }

    public static function incompleteBodies(): iterable
    {
        yield 'without the moon' => [['corporationId' => self::ILYRA_CORPORATION_ID, 'scan' => "Bitumens\t100 %"]];
        yield 'without the corporation' => [['moonId' => self::KNOWN_VALUE_MOON_ID, 'scan' => "Bitumens\t100 %"]];
        yield 'without the scan' => [['corporationId' => self::ILYRA_CORPORATION_ID, 'moonId' => self::KNOWN_VALUE_MOON_ID]];
    }

    #[DataProvider('incompleteBodies')]
    public function testABodyWithoutOneOfTheThreeFieldsIsRefusedAsUnprocessableAndStoresNothing(array $body): void
    {
        $this->as($this->aria);

        $this->request('POST', self::ENDPOINT, $body);

        self::assertSame([422, 0], [$this->client->getResponse()->getStatusCode(), $this->storedCompositionCount()]);
    }

    public function testAnonymousVisitorCannotRecordAComposition(): void
    {
        $this->as(null);

        $this->request('POST', self::ENDPOINT, $this->payload(self::ILYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID, "Bitumens\t100 %"));

        self::assertSame([401, 0], [$this->client->getResponse()->getStatusCode(), $this->storedCompositionCount()]);
    }

    private function payload(int $corporationId, int $moonId, string $scan): array
    {
        return ['corporationId' => $corporationId, 'moonId' => $moonId, 'scan' => $scan];
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? $this->client->getResponse()->getStatusCode(), $error['detail'] ?? null];
    }
}
