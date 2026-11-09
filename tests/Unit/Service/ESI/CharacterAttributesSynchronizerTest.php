<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\CachedCharacterAttributes;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Repository\CachedCharacterAttributesRepository;
use App\Service\ESI\CharacterAttributesSynchronizer;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterAttributesSynchronizer::class)]
#[Group('issue-359')]
final class CharacterAttributesSynchronizerTest extends TestCase
{
    private const string ATTRIBUTES_ENDPOINT = '/characters/2112000359/attributes/';
    private const string PREVIOUS_SYNC = '2026-11-03T09:30:00+00:00';

    private Character $character;
    private EveToken $token;
    private ?CachedCharacterAttributes $storedAttributes = null;
    private array $esiEndpointCalls = [];
    private array $persisted = [];
    private int $flushCount = 0;

    protected function setUp(): void
    {
        $this->character = (new Character())->setName('Attributes Pilot')->setEveCharacterId(2_112_000_359);
        $this->token = new EveToken();
    }

    public function testCreatesTheAttributesLineOfACharacterSeenForTheFirstTime(): void
    {
        $this->synchronize($this->esiAttributes(20, 21, 22, 23, 19));

        $this->assertSame([[self::ATTRIBUTES_ENDPOINT, $this->token]], $this->esiEndpointCalls);
        $this->assertCount(1, $this->persisted);
        $this->assertSame([20, 21, 22, 23, 19], $this->rowOf($this->persisted[0]));
        $this->assertSame($this->character, $this->persisted[0]->getCharacter());
        $this->assertSame(1, $this->flushCount);
    }

    public function testUpdatesTheStoredLineInsteadOfCreatingASecondOne(): void
    {
        $this->storedAttributes = $this->storedLine(17, 17, 17, 17, 17);

        $this->synchronize($this->esiAttributes(27, 21, 20, 19, 18));

        $this->assertSame([], $this->persisted);
        $this->assertSame([27, 21, 20, 19, 18], $this->rowOf($this->storedAttributes));
    }

    public function testStampsTheLineWithTheSyncTime(): void
    {
        $this->storedAttributes = $this->storedLine(17, 17, 17, 17, 17);
        $syncStartedAt = new \DateTimeImmutable();

        $this->synchronize($this->esiAttributes(27, 21, 20, 19, 18));

        $this->assertGreaterThanOrEqual($syncStartedAt, $this->storedAttributes->getCachedAt());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableResponses(): iterable
    {
        yield 'empty list' => [[]];
        yield 'attribute missing' => [['intelligence' => 20, 'memory' => 21, 'perception' => 22, 'willpower' => 23]];
        yield 'attribute as string' => [['intelligence' => '20', 'memory' => 21, 'perception' => 22, 'willpower' => 23, 'charisma' => 19]];
        yield 'attribute as float' => [['intelligence' => 20.5, 'memory' => 21, 'perception' => 22, 'willpower' => 23, 'charisma' => 19]];
        yield 'attribute null' => [['intelligence' => null, 'memory' => 21, 'perception' => 22, 'willpower' => 23, 'charisma' => 19]];
    }

    #[DataProvider('unusableResponses')]
    public function testRefusesAResponseWithoutFiveIntegerAttributesAndWritesNothing(array $esiResponse): void
    {
        $this->storedAttributes = $this->storedLine(17, 17, 17, 17, 17);

        try {
            $this->synchronize($esiResponse);
            $this->fail('UnexpectedValueException expected');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('2112000359', $exception->getMessage());
        }

        $this->assertSame([17, 17, 17, 17, 17], $this->rowOf($this->storedAttributes));
        $this->assertSame([[], 0], [$this->persisted, $this->flushCount]);
    }

    private function esiAttributes(int $intelligence, int $memory, int $perception, int $willpower, int $charisma): array
    {
        return [
            'intelligence' => $intelligence,
            'memory' => $memory,
            'perception' => $perception,
            'willpower' => $willpower,
            'charisma' => $charisma,
            'bonus_remaps' => 2,
        ];
    }

    private function storedLine(int $intelligence, int $memory, int $perception, int $willpower, int $charisma): CachedCharacterAttributes
    {
        return (new CachedCharacterAttributes())
            ->setCharacter($this->character)
            ->setAttributes($intelligence, $memory, $perception, $willpower, $charisma)
            ->setCachedAt(new \DateTimeImmutable(self::PREVIOUS_SYNC));
    }

    private function rowOf(CachedCharacterAttributes $attributes): array
    {
        return [
            $attributes->getIntelligence(),
            $attributes->getMemory(),
            $attributes->getPerception(),
            $attributes->getWillpower(),
            $attributes->getCharisma(),
        ];
    }

    private function synchronize(array $esiResponse): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint, ?EveToken $token) use ($esiResponse): array {
            $this->esiEndpointCalls[] = [$endpoint, $token];

            return $esiResponse;
        });

        $repository = $this->createStub(CachedCharacterAttributesRepository::class);
        $repository->method('findOneBy')->willReturnCallback(fn (): ?CachedCharacterAttributes => $this->storedAttributes);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        (new CharacterAttributesSynchronizer($esiClient, $repository, $entityManager))->sync($this->character, $this->token);
    }
}
