<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-998')]
final class StructureListTransferApiTest extends WebTestCase
{
    private const int CORPORATION_ID = 98_998_001;
    private const string ME_RIG = 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency I';

    private static int $nextEveCharacterId = 2_113_998_001;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testExportThenImportOverHttpReproducesTheStructureOnAnotherAccount(): void
    {
        $exporter = $this->persistPilot('Exporter');
        $importer = $this->persistPilot('Importer');
        $this->em->persist(
            (new IndustryStructureConfig())
                ->setUser($exporter)
                ->setName('Home Raitaru')
                ->setSecurityType('nullsec')
                ->setStructureType('raitaru')
                ->setRigs([self::ME_RIG])
                ->setFacilityTaxPercent(3.5),
        );
        $this->em->flush();

        $this->client->loginUser($exporter, 'api');
        $this->request('GET', '/api/industry/structures/export');
        self::assertResponseStatusCodeSame(200);
        $exported = $this->responseJson();

        $importerId = $importer->getId();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $importer = $this->em->find(User::class, $importerId);
        \assert($importer instanceof User);
        $this->client->loginUser($importer, 'api');
        $this->request('POST', '/api/industry/structures/import', ['entries' => $exported['structures'], 'overwriteExisting' => false, 'dryRun' => false]);

        self::assertResponseStatusCodeSame(200);
        $result = $this->responseJson();
        self::assertSame(['create'], array_column($result['entries'], 'status'));
        self::assertTrue($result['applied']);
        $this->em->clear();
        $stored = $this->em->getRepository(IndustryStructureConfig::class)->findBy(['user' => $importerId]);
        self::assertSame(['Home Raitaru'], array_map(static fn (IndustryStructureConfig $structure): string => $structure->getName(), $stored));
        self::assertSame([self::ME_RIG], $stored[0]->getRigs());
        self::assertSame(3.5, $stored[0]->getFacilityTaxPercent());
    }

    public function testAnonymousVisitorIsRefusedOnBothEndpoints(): void
    {
        $this->request('GET', '/api/industry/structures/export');
        $exportStatus = $this->client->getResponse()->getStatusCode();
        $this->request('POST', '/api/industry/structures/import', ['entries' => [], 'overwriteExisting' => false, 'dryRun' => true]);

        self::assertSame([401, 401], [$exportStatus, $this->client->getResponse()->getStatusCode()]);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function persistPilot(string $name): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Test Corp');
        $token = (new EveToken())
            ->setAccessToken('access-'.$name)
            ->setRefreshTokenEncrypted('refresh-'.$name)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes([]);
        $character->setEveToken($token);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->persist($token);
        $this->em->flush();

        return $user;
    }
}
