<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Me;

use App\Entity\Character;
use App\Entity\User;
use App\Service\Account\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-53')]
final class DeleteAccountConfirmationTest extends WebTestCase
{
    private const string MAIN_CHARACTER_NAME = 'Kaelen Vorn';
    private const string ALT_CHARACTER_NAME = 'Sera Thalwyn';
    private const int MAIN_EVE_CHARACTER_ID = 2_112_053_001;
    private const int ALT_EVE_CHARACTER_ID = 2_112_053_002;
    private const int CORPORATION_ID = 98_053_001;
    private const string CORPORATION_NAME = 'Vorn Deep Core Mining';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AccountDeletionService&MockObject $accountDeletionService;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->accountDeletionService = $this->createMock(AccountDeletionService::class);
        self::getContainer()->set(AccountDeletionService::class, $this->accountDeletionService);
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testConfirmationWithTheMainCharacterNameDeletesTheAccount(): void
    {
        $user = $this->persistUserWithMainAndAlt();
        $userId = $user->getId()?->toRfc4122();

        $this->accountDeletionService->expects(self::once())
            ->method('delete')
            ->with(self::callback(static fn (User $deletedUser): bool => $deletedUser->getId()?->toRfc4122() === $userId));

        $this->client->loginUser($user, 'api');
        $this->deleteAccount(json_encode(['confirmation' => self::MAIN_CHARACTER_NAME], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(204);
    }

    public function testBodyWithoutConfirmationIsRejectedWith422AndDeletesNothing(): void
    {
        $user = $this->persistUserWithMainAndAlt();

        $this->accountDeletionService->expects(self::never())->method('delete');

        $this->client->loginUser($user, 'api');
        $this->deleteAccount('{}');

        self::assertResponseStatusCodeSame(422);
    }

    public function testConfirmationWithTheNameOfAnAltIsRejectedWith422AndDeletesNothing(): void
    {
        $user = $this->persistUserWithMainAndAlt();

        $this->accountDeletionService->expects(self::never())->method('delete');

        $this->client->loginUser($user, 'api');
        $this->deleteAccount(json_encode(['confirmation' => self::ALT_CHARACTER_NAME], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    private function persistUserWithMainAndAlt(): User
    {
        $user = new User();
        $mainCharacter = $this->character(self::MAIN_EVE_CHARACTER_ID, self::MAIN_CHARACTER_NAME);
        $user->addCharacter($mainCharacter);
        $user->addCharacter($this->character(self::ALT_EVE_CHARACTER_ID, self::ALT_CHARACTER_NAME));
        $user->setMainCharacter($mainCharacter);
        $this->em->persist($user);
        foreach ($user->getCharacters() as $character) {
            $this->em->persist($character);
        }
        $this->em->flush();

        return $user;
    }

    private function character(int $eveCharacterId, string $name): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName(self::CORPORATION_NAME);
    }

    private function deleteAccount(string $body): void
    {
        $this->client->request(
            'DELETE',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body,
        );
    }
}
