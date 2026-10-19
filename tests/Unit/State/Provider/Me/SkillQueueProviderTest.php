<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Me;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Me\SkillQueueEntryResource;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\TypeNameResolver;
use App\State\Provider\Me\SkillQueueProvider;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(SkillQueueProvider::class)]
final class SkillQueueProviderTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int EVE_CHARACTER_ID = 2112000101;
    private const int INDUSTRY_SKILL_TYPE_ID = 3380;
    private const string INDUSTRY_SKILL_NAME = 'Industry';
    private const string COMPLETED_FINISH_DATE = '2020-06-01T10:00:00Z';
    private const string CURRENT_FINISH_DATE = '2099-03-14T08:30:00Z';
    private const string NEXT_FINISH_DATE = '2099-04-02T17:45:00Z';

    public function testCurrentlyTrainingSkillKnownToTheSdeKeepsItsSdeName(): void
    {
        $entry = $this->provideSingleSkillQueueEntry([
            ['skill_id' => self::INDUSTRY_SKILL_TYPE_ID, 'finished_level' => 4, 'finish_date' => self::COMPLETED_FINISH_DATE],
            ['skill_id' => self::INDUSTRY_SKILL_TYPE_ID, 'finished_level' => 5, 'finish_date' => self::CURRENT_FINISH_DATE],
        ]);

        $this->assertSame(self::INDUSTRY_SKILL_TYPE_ID, $entry->skillId);
        $this->assertSame(self::INDUSTRY_SKILL_NAME, $entry->skillName);
        $this->assertSame(5, $entry->finishedLevel);
        $this->assertSame(self::CURRENT_FINISH_DATE, $entry->finishDate);
        $this->assertSame(1, $entry->queueSize);
    }

    public function testCurrentlyTrainingSkillAbsentFromTheSdeHasAnUnknownTypeName(): void
    {
        $entry = $this->provideSingleSkillQueueEntry([
            ['skill_id' => self::INDUSTRY_SKILL_TYPE_ID, 'finished_level' => 4, 'finish_date' => self::COMPLETED_FINISH_DATE],
            ['skill_id' => self::ROBOTICS_TYPE_ID, 'finished_level' => 3, 'finish_date' => self::CURRENT_FINISH_DATE],
            ['skill_id' => self::INDUSTRY_SKILL_TYPE_ID, 'finished_level' => 5, 'finish_date' => self::NEXT_FINISH_DATE],
        ]);

        $this->assertSame(self::ROBOTICS_TYPE_ID, $entry->skillId);
        $this->assertNull($entry->skillName);
        $this->assertSame(3, $entry->finishedLevel);
        $this->assertSame(self::CURRENT_FINISH_DATE, $entry->finishDate);
        $this->assertSame(2, $entry->queueSize);
    }

    private function provideSingleSkillQueueEntry(array $esiSkillQueue): SkillQueueEntryResource
    {
        $character = new Character();
        $character->setEveCharacterId(self::EVE_CHARACTER_ID);
        $token = new EveToken();
        $token->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
        $character->setEveToken($token);
        $user = new User();
        $user->addCharacter($character);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === '/characters/'.self::EVE_CHARACTER_ID.'/skillqueue/'
                ? $esiSkillQueue
                : throw new \LogicException('Unexpected ESI GET '.$endpoint),
        );

        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('valid-access-token');

        $provider = new SkillQueueProvider(
            $security,
            $esiClient,
            $tokenManager,
            new TypeNameResolver($this->sdeTypeRepositoryKnowing([self::INDUSTRY_SKILL_TYPE_ID => self::INDUSTRY_SKILL_NAME])),
        );

        $skillQueue = $provider->provide(new Get());

        $this->assertCount(1, $skillQueue->skillQueues);

        return $skillQueue->skillQueues[0];
    }
}
