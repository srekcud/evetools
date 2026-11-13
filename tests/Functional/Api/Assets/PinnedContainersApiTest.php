<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Assets;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-893')]
final class PinnedContainersApiTest extends WebTestCase
{
    private const int CORPORATION_ID = 98_893_501;
    private const int VISIBLE_DIVISION = 2;
    private const int VISIBLE_BOX_ITEM_ID = 1_893_500_001;
    private const int PROJECT_BOX_ITEM_ID = 1_893_500_002;
    private const int HIDDEN_BOX_ITEM_ID = 1_893_500_003;
    private const string PATH = '/api/me/pinned-containers';

    private static int $nextEveCharacterId = 2_113_893_501;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $loggedInUser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testUserPinsAVisibleContainerReadsItBackAndUnpinsIt(): void
    {
        $user = $this->persistPilotWithCorporationBoxes();
        $this->loggedInUser = $user;

        $this->send('POST', self::PATH, ['itemId' => self::VISIBLE_BOX_ITEM_ID]);
        self::assertResponseIsSuccessful();

        $this->send('GET', self::PATH);
        self::assertResponseIsSuccessful();
        $entries = $this->responseJson();
        self::assertCount(1, $entries);
        self::assertSame(self::VISIBLE_BOX_ITEM_ID, $entries[0]['itemId']);
        self::assertSame('Sylvain - Reactions', $entries[0]['name']);
        self::assertSame('Reactions', $entries[0]['divisionName']);
        self::assertTrue($entries[0]['found']);
        self::assertNull($entries[0]['projectId']);

        $this->send('DELETE', self::PATH.'/'.self::VISIBLE_BOX_ITEM_ID);
        self::assertResponseStatusCodeSame(204);

        $this->send('GET', self::PATH);
        self::assertSame([], $this->responseJson());
    }

    public function testPinningAContainerOfAHiddenDivisionIsNotFound(): void
    {
        $user = $this->persistPilotWithCorporationBoxes();
        $this->loggedInUser = $user;

        $this->send('POST', self::PATH, ['itemId' => self::HIDDEN_BOX_ITEM_ID]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testProjectContainerIsPinnedAutomaticallyAndCannotBeUnpinned(): void
    {
        $user = $this->persistPilotWithCorporationBoxes();
        $project = (new IndustryProject())->setOwner($user)->setName('Capital rush')->setStatus(ProjectStatus::InProgress)->setContainerNames(['Capital box']);
        $this->em->persist($project);
        $this->em->flush();
        $this->loggedInUser = $user;

        $this->send('GET', self::PATH);
        $entries = $this->responseJson();
        self::assertCount(1, $entries);
        self::assertSame(self::PROJECT_BOX_ITEM_ID, $entries[0]['itemId']);
        self::assertSame('Capital rush', $entries[0]['projectName']);

        $this->send('DELETE', self::PATH.'/'.self::PROJECT_BOX_ITEM_ID);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPinsOfAnotherUserAreNotListed(): void
    {
        $owner = $this->persistPilotWithCorporationBoxes();
        $other = $this->persistPilot();
        $this->loggedInUser = $owner;
        $this->send('POST', self::PATH, ['itemId' => self::VISIBLE_BOX_ITEM_ID]);

        $this->loggedInUser = $other;
        $this->send('GET', self::PATH);

        self::assertSame([], $this->responseJson());
    }

    private function persistPilot(): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName('Pilot '.self::$nextEveCharacterId)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Solberg Industries');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistPilotWithCorporationBoxes(): User
    {
        $user = $this->persistPilot();
        $visibility = (new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions([self::VISIBLE_DIVISION])
            ->setConfiguredBy($user);
        $this->em->persist($visibility);
        $this->em->persist($this->box(self::VISIBLE_BOX_ITEM_ID, 'Sylvain - Reactions', 'CorpSAG2', 'Reactions'));
        $this->em->persist($this->box(self::PROJECT_BOX_ITEM_ID, 'Capital box', 'CorpSAG2', 'Reactions'));
        $this->em->persist($this->box(self::HIDDEN_BOX_ITEM_ID, 'Secret box', 'CorpSAG5', 'Vault'));
        $this->em->flush();

        return $user;
    }

    private function box(int $itemId, string $name, string $flag, string $division): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setItemName($name)
            ->setTypeId(17_366)
            ->setTypeName('Giant Secure Container')
            ->setQuantity(1)
            ->setLocationId(60_003_760)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setLocationFlag($flag)
            ->setDivisionName($division)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
    }

    private function send(string $method, string $path, ?array $body = null): void
    {
        $userId = $this->loggedInUser->getId();
        $this->em->clear();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
        $this->client->request(
            $method,
            $path,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
