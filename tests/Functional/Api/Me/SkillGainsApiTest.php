<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Me;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-359')]
final class SkillGainsApiTest extends WebTestCase
{
    private const int MAREN_EVE_CHARACTER_ID = 2_112_900_201;
    private const int BRANN_EVE_CHARACTER_ID = 2_112_900_202;
    private const int INDUSTRY = 3380;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $maren;
    private User $brann;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->maren = $this->createPilot('Maren Skills', self::MAREN_EVE_CHARACTER_ID);
        $this->brann = $this->createPilot('Brann Skills', self::BRANN_EVE_CHARACTER_ID);
    }

    public function testSkillGainsRequireAuthentication(): void
    {
        $this->client->request('GET', $this->uriOf($this->maren), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnotherUsersCharacterIsNotFound(): void
    {
        $this->client->loginUser($this->maren, 'api');
        $this->client->request('GET', $this->uriOf($this->brann), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testACharacterWithoutCachedSkillsHasEveryGainUnknownWithItsCause(): void
    {
        $body = $this->fetch($this->maren);

        self::assertSame(['characterId', 'period', 'iskPerHour', 'lines'], array_slice(array_keys($body), -4));
        self::assertSame(['from', 'to', 'days'], array_keys($body['period']));
        self::assertSame(30, $body['period']['days']);
        self::assertNull($body['iskPerHour']);
        self::assertCount(9, $body['lines']);
        foreach ($body['lines'] as $line) {
            self::assertSame('skills_not_synchronized', $line['gainUnknownReason']);
            self::assertNull($line['monthlyGain']);
        }
    }

    public function testALineCarriesTheLevelsTheEffectTheGainAndTheTrainingFigures(): void
    {
        $this->cacheSkill($this->maren, self::INDUSTRY, 2, 8_000);

        $lines = $this->fetch($this->maren)['lines'];
        $industry = array_values(array_filter($lines, static fn (array $line): bool => $line['skillId'] === self::INDUSTRY))[0];

        self::assertSame(
            ['skillId', 'currentLevel', 'targetLevel', 'effect', 'hoursSavedPerMonth', 'monthlyGain', 'gainUnknownReason', 'trainingSeconds', 'trainingUnknownReason', 'gainPerTrainingDay'],
            array_keys($industry),
        );
        self::assertSame([2, 3, 'manufacturing_time'], [$industry['currentLevel'], $industry['targetLevel'], $industry['effect']]);
        self::assertSame('isk_per_hour_unknown', $industry['gainUnknownReason']);
        self::assertSame('skill_not_in_sde', $industry['trainingUnknownReason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(User $user): array
    {
        $this->client->loginUser($user, 'api');
        $this->client->request('GET', $this->uriOf($user), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function uriOf(User $user): string
    {
        return '/api/me/characters/' . $user->getMainCharacter()->getId()->toRfc4122() . '/skill-gains';
    }

    private function cacheSkill(User $user, int $skillId, int $level, int $points): void
    {
        $this->em->persist(
            (new CachedCharacterSkill())->setCharacter($user->getMainCharacter())->setSkillId($skillId)->setLevel($level)->setSkillPoints($points),
        );
        $this->em->flush();
    }

    private function createPilot(string $name, int $eveCharacterId): User
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_900_001)
            ->setCorporationName('Esk Ore Haulers');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
