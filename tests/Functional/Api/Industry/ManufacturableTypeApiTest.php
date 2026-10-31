<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Character;
use App\Entity\User;
use App\Tests\Fixtures\Sde\ManufacturableProductSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-238')]
final class ManufacturableTypeApiTest extends WebTestCase
{
    private const int EVE_CHARACTER_ID = 2_112_238_001;

    private KernelBrowser $client;
    private User $pilot;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        ManufacturableProductSde::persist($em);
        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Vessa Harrow')
            ->setCorporationId(98_238_001)
            ->setCorporationName('Harrow Hull Works');
        $this->pilot = new User();
        $this->pilot->addCharacter($character);
        $this->pilot->setMainCharacter($character);
        $em->persist($this->pilot);
        $em->persist($character);
        $em->flush();
    }

    public function testT1ProductIsReturnedAsNotT2(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->requestType((string) ManufacturableProductSde::FEROX);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['typeId' => ManufacturableProductSde::FEROX, 'typeName' => ManufacturableProductSde::FEROX_NAME, 'isT2' => false],
            $this->responseJson(),
        );
    }

    public function testT2ProductIsReturnedAsT2ToAnOrdinaryUser(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->requestType((string) ManufacturableProductSde::HECATE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['typeId' => ManufacturableProductSde::HECATE, 'typeName' => ManufacturableProductSde::HECATE_NAME, 'isT2' => true],
            $this->responseJson(),
        );
    }

    public function testReactionProductIsReturnedAsNotT2(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->requestType((string) ManufacturableProductSde::FERNITE_CARBIDE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['typeId' => ManufacturableProductSde::FERNITE_CARBIDE, 'typeName' => ManufacturableProductSde::FERNITE_CARBIDE_NAME, 'isT2' => false],
            $this->responseJson(),
        );
    }

    #[DataProvider('searchedProducts')]
    public function testTypeLookupAnswersTheSearchRowOfTheSameProduct(string $searchedName, int $typeId): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/industry/search?q='.rawurlencode($searchedName), server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        $searchRows = array_values(array_filter(
            $this->responseJson()['results'],
            static fn (array $row): bool => $row['typeId'] === $typeId,
        ));

        $this->requestType((string) $typeId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($searchRows, [$this->responseJson()]);
    }

    public static function searchedProducts(): iterable
    {
        yield 'T1 ship' => [ManufacturableProductSde::FEROX_NAME, ManufacturableProductSde::FEROX];
        yield 'T2 ship' => [ManufacturableProductSde::HECATE_NAME, ManufacturableProductSde::HECATE];
        yield 'reaction product' => [ManufacturableProductSde::FERNITE_CARBIDE_NAME, ManufacturableProductSde::FERNITE_CARBIDE];
    }

    #[DataProvider('searchedT2Flags')]
    public function testSearchKeepsTheT2FlagOfEachProduct(string $searchedName, array $expectedFixtureRows): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/industry/search?q='.rawurlencode($searchedName), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($expectedFixtureRows, array_values(array_filter(
            $this->responseJson()['results'],
            static fn (array $row): bool => in_array($row['typeId'], ManufacturableProductSde::FIXTURE_TYPE_IDS, true),
        )));
    }

    public static function searchedT2Flags(): iterable
    {
        yield 'T1 ship' => [ManufacturableProductSde::FEROX_NAME, [
            ['typeId' => ManufacturableProductSde::FEROX, 'typeName' => ManufacturableProductSde::FEROX_NAME, 'isT2' => false],
        ]];
        yield 'T2 ship, neither its blueprint nor the unpublished hull' => [ManufacturableProductSde::HECATE_NAME, [
            ['typeId' => ManufacturableProductSde::HECATE, 'typeName' => ManufacturableProductSde::HECATE_NAME, 'isT2' => true],
        ]];
        yield 'reaction product, not its formula' => [ManufacturableProductSde::FERNITE_CARBIDE_NAME, [
            ['typeId' => ManufacturableProductSde::FERNITE_CARBIDE, 'typeName' => ManufacturableProductSde::FERNITE_CARBIDE_NAME, 'isT2' => false],
        ]];
    }

    #[DataProvider('typesThatAreNotManufacturableProducts')]
    public function testTypeThatIsNotAManufacturableProductIsNotFound(int $typeId): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->requestType((string) $typeId);

        self::assertResponseStatusCodeSame(404);
    }

    public static function typesThatAreNotManufacturableProducts(): iterable
    {
        yield 'invented blueprint' => [ManufacturableProductSde::HECATE_BLUEPRINT];
        yield 'type absent from the SDE' => [ManufacturableProductSde::ABSENT_FROM_SDE];
        yield 'unpublished manufactured product' => [ManufacturableProductSde::UNPUBLISHED_PRODUCT];
    }

    #[DataProvider('invalidTypeIds')]
    public function testInvalidTypeIdIsNotFound(string $typeId): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->requestType($typeId);

        self::assertResponseStatusCodeSame(404);
    }

    public static function invalidTypeIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'letters' => ['abc'];
        yield 'decimal' => ['1.5'];
        yield 'leading zeros' => ['007'];
        yield 'beyond a PHP integer' => ['99999999999999999999'];
    }

    public function testAnonymousCallIsUnauthorized(): void
    {
        $this->requestType((string) ManufacturableProductSde::HECATE);

        self::assertResponseStatusCodeSame(401);
    }

    private function requestType(string $typeId): void
    {
        $this->client->request('GET', '/api/industry/types/'.$typeId, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
