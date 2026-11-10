<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Me;

use App\Entity\CachedAsset;
use App\Entity\CachedStructure;
use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-580')]
final class UserSettingsPreferredStructureAccessTest extends WebTestCase
{
    private const int CACHED_STRUCTURE_ID = 1_030_580_000_001;
    private const int ASSET_STRUCTURE_ID = 1_030_580_000_002;
    private const int OTHER_USER_ASSET_STRUCTURE_ID = 1_030_580_000_003;
    private const int UNKNOWN_STRUCTURE_ID = 1_030_580_000_004;
    private const int STORED_STRUCTURE_ID = 1_030_580_000_005;
    private const string STORED_STRUCTURE_NAME = 'Stored Keepstar';
    private const int CORPORATION_ID = 98_580_001;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $nextEveCharacterId = 2_112_580_001;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testStructureUnknownToTheCacheAndToTheCharactersIsRefusedWith422AndNothingIsStored(): void
    {
        $user = $this->persistUserWithCharacter();

        $this->patch($user, ['preferredMarketStructureId' => self::UNKNOWN_STRUCTURE_ID, 'preferredMarketStructureName' => 'Nowhere']);

        self::assertResponseStatusCodeSame(422);
        $this->em->refresh($user);
        self::assertNull($user->getPreferredMarketStructureId());
        self::assertNull($user->getPreferredMarketStructureName());
    }

    public function testStructureHeldByTheStructureCacheIsAccepted(): void
    {
        $user = $this->persistUserWithCharacter();
        $this->persistCachedStructure(self::CACHED_STRUCTURE_ID);

        $this->patch($user, ['preferredMarketStructureId' => self::CACHED_STRUCTURE_ID, 'preferredMarketStructureName' => 'Cached Fortizar']);

        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($user);
        self::assertSame(self::CACHED_STRUCTURE_ID, $user->getPreferredMarketStructureId());
    }

    public function testStructureWhereTheUserOwnsAPersonalAssetIsAcceptedEvenWhenNotCached(): void
    {
        $user = $this->persistUserWithCharacter();
        $this->persistPersonalAsset($user->getCharacters()->first(), self::ASSET_STRUCTURE_ID);

        $this->patch($user, ['preferredMarketStructureId' => self::ASSET_STRUCTURE_ID, 'preferredMarketStructureName' => 'Asset Astrahus']);

        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($user);
        self::assertSame(self::ASSET_STRUCTURE_ID, $user->getPreferredMarketStructureId());
    }

    public function testStructureWhereOnlyAnotherUserOwnsAnAssetIsRefusedWith422(): void
    {
        $user = $this->persistUserWithCharacter();
        $otherUser = $this->persistUserWithCharacter();
        $this->persistPersonalAsset($otherUser->getCharacters()->first(), self::OTHER_USER_ASSET_STRUCTURE_ID);

        $this->patch($user, ['preferredMarketStructureId' => self::OTHER_USER_ASSET_STRUCTURE_ID, 'preferredMarketStructureName' => 'Their Raitaru']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNullClearsTheStoredStructureAndItsName(): void
    {
        $user = $this->persistUserWithCharacter();
        $user->setPreferredMarketStructureId(self::STORED_STRUCTURE_ID);
        $user->setPreferredMarketStructureName(self::STORED_STRUCTURE_NAME);
        $this->em->flush();

        $this->patch($user, ['preferredMarketStructureId' => null]);

        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($user);
        self::assertNull($user->getPreferredMarketStructureId());
        self::assertNull($user->getPreferredMarketStructureName());
    }

    public function testStoredStructureNoLongerKnownIsAcceptedWhenSentUnchangedWithANewName(): void
    {
        $user = $this->persistUserWithCharacter();
        $user->setPreferredMarketStructureId(self::STORED_STRUCTURE_ID);
        $user->setPreferredMarketStructureName(self::STORED_STRUCTURE_NAME);
        $this->em->flush();

        $this->patch($user, ['preferredMarketStructureId' => self::STORED_STRUCTURE_ID, 'preferredMarketStructureName' => 'Renamed Keepstar']);

        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($user);
        self::assertSame(self::STORED_STRUCTURE_ID, $user->getPreferredMarketStructureId());
        self::assertSame('Renamed Keepstar', $user->getPreferredMarketStructureName());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function patch(User $user, array $payload): void
    {
        $this->client->loginUser($user, 'api');
        $this->client->request(
            'PATCH',
            '/api/me/settings',
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function persistUserWithCharacter(): User
    {
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName('Pilot ' . $this->nextEveCharacterId)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Settings Test Corp');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistCachedStructure(int $structureId): void
    {
        $structure = (new CachedStructure())
            ->setStructureId($structureId)
            ->setName('Cached Fortizar');
        $this->em->persist($structure);
        $this->em->flush();
    }

    private function persistPersonalAsset(Character $character, int $structureId): void
    {
        $asset = (new CachedAsset())
            ->setItemId($structureId + 9_000)
            ->setTypeId(34)
            ->setTypeName('Tritanium')
            ->setQuantity(10)
            ->setLocationId($structureId)
            ->setLocationName('Some Structure')
            ->setLocationType('structure')
            ->setCharacter($character)
            ->setIsCorporationAsset(false);
        $this->em->persist($asset);
        $this->em->flush();
    }
}
