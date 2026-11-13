<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\SdeBuildStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-848')]
final class AdminStatsSdeBuildApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_848_201;
    private const OTHER_EVE_CHARACTER_ID = 2_113_848_202;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $_ENV['ADMIN_CHARACTER_IDS'] = $_SERVER['ADMIN_CHARACTER_IDS'] = (string) self::ADMIN_EVE_CHARACTER_ID;
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_CHARACTER_IDS'] = $_SERVER['ADMIN_CHARACTER_IDS'] = '';
        parent::tearDown();
    }

    public function testStatsExposeTheImportedBuildAndTheLatestCcpBuild(): void
    {
        $repository = self::getContainer()->get(SdeBuildStateRepository::class);
        $state = $repository->current();
        $state
            ->setImportedBuildNumber(3213160)
            ->setImportedReleaseDate(new \DateTimeImmutable('2026-06-02T10:00:00Z'))
            ->setImportedAt(new \DateTimeImmutable('2026-06-03T08:15:00Z'))
            ->setLatestBuildNumber(3693310)
            ->setLatestReleaseDate(new \DateTimeImmutable('2026-11-12T11:07:00Z'))
            ->setLatestCheckedAt(new \DateTimeImmutable('2026-11-13T15:30:00Z'));
        $repository->save($state);
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');

        $block = $this->requestSdeBuildBlock();

        self::assertSame('outdated', $block['status']);
        self::assertSame(3213160, $block['importedBuildNumber']);
        self::assertSame('2026-06-02T10:00:00+00:00', $block['importedReleaseDate']);
        self::assertSame('2026-06-03T08:15:00+00:00', $block['importedAt']);
        self::assertSame(3693310, $block['latestBuildNumber']);
        self::assertSame('2026-11-12T11:07:00+00:00', $block['latestReleaseDate']);
        self::assertSame('2026-11-13T15:30:00+00:00', $block['latestCheckedAt']);
    }

    public function testStatsExposeAnUnknownStatusWithNullBuildsOnAnEmptyTable(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');

        $block = $this->requestSdeBuildBlock();

        self::assertSame('unknown', $block['status']);
        foreach (['importedBuildNumber', 'importedReleaseDate', 'importedAt', 'latestBuildNumber', 'latestReleaseDate', 'latestCheckedAt'] as $key) {
            self::assertArrayHasKey($key, $block);
            self::assertNull($block[$key], $key);
        }
    }

    public function testAUserWhoIsNotAdminDoesNotSeeTheBlock(): void
    {
        $this->client->loginUser($this->persistedUser(self::OTHER_EVE_CHARACTER_ID), 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
    }

    private function requestSdeBuildBlock(): array
    {
        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('sdeBuild', $payload);

        return $payload['sdeBuild'];
    }

    private function persistedUser(int $eveCharacterId): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot ' . $eveCharacterId)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
