<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Me;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\DeleteAccountInput;
use App\Entity\Character;
use App\Entity\User;
use App\Security\JwtBlacklist;
use App\Service\Account\AccountDeletionService;
use App\State\Processor\Me\DeleteAccountProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(DeleteAccountProcessor::class)]
#[Group('issue-53')]
final class DeleteAccountProcessorTest extends TestCase
{
    private const string REQUEST_JWT = 'eyJhbGciOiJSUzI1NiJ9.account-of-kaelen.signature';
    private const string OTHER_JWT = 'eyJhbGciOiJSUzI1NiJ9.other-device-of-kaelen.signature';
    private const int JWT_TOKEN_TTL = 3_600;
    private const string MAIN_CHARACTER_NAME = 'Kaelen Vorn';
    private const string ALT_CHARACTER_NAME = 'Sera Thalwyn';

    private JwtBlacklist $jwtBlacklist;
    private RequestStack $requestStack;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->jwtBlacklist = new JwtBlacklist(jwtBlacklist: new ArrayAdapter(), jwtTokenTtl: self::JWT_TOKEN_TTL);
        $this->logger = new BufferingLogger();
        $this->requestStack = new RequestStack();
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . self::REQUEST_JWT);
        $this->requestStack->push($request);
    }

    public function testDeletesTheAccountOfTheCurrentUserAndBlocksTheJwtOfTheRequest(): void
    {
        $currentUser = $this->userWithMainAndAlt();
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::once())->method('delete')->with(self::identicalTo($currentUser));

        $result = $this->processor($currentUser, $accountDeletionService)->process($this->confirmation(self::MAIN_CHARACTER_NAME), new Delete());

        self::assertNull($result);
        self::assertTrue($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
        self::assertFalse($this->jwtBlacklist->isBlocked(self::OTHER_JWT));
    }

    public function testJwtOfTheRequestIsNotBlockedWhenTheDeletionFails(): void
    {
        $accountDeletionService = $this->createStub(AccountDeletionService::class);
        $accountDeletionService->method('delete')->willThrowException(new \RuntimeException('deletion failed'));
        $processor = $this->processor($this->userWithMainAndAlt(), $accountDeletionService);

        try {
            $processor->process($this->confirmation(self::MAIN_CHARACTER_NAME), new Delete());
            self::fail('The deletion failure must reach the caller');
        } catch (\RuntimeException $exception) {
            self::assertSame('deletion failed', $exception->getMessage());
        }

        self::assertFalse($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
    }

    public function testAnonymousRequestIsUnauthorizedAndDeletesNothing(): void
    {
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::never())->method('delete');
        $processor = $this->processor(null, $accountDeletionService);

        try {
            $processor->process($this->confirmation(self::MAIN_CHARACTER_NAME), new Delete());
            self::fail('An anonymous request must be unauthorized');
        } catch (UnauthorizedHttpException $exception) {
            self::assertSame(401, $exception->getStatusCode());
        }

        self::assertFalse($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
    }

    public function testJwtBlocklistFailureAfterTheDeletionIsLoggedWithoutTheJwtAndDoesNotFailTheRequest(): void
    {
        $failingPool = $this->createStub(CacheItemPoolInterface::class);
        $failingPool->method('getItem')->willThrowException(new class('Redis unreachable') extends \RuntimeException implements CacheException {});
        $failingPool->method('save')->willThrowException(new class('Redis unreachable') extends \RuntimeException implements CacheException {});
        $this->jwtBlacklist = new JwtBlacklist(jwtBlacklist: $failingPool, jwtTokenTtl: self::JWT_TOKEN_TTL);
        $currentUser = $this->userWithMainAndAlt();
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::once())->method('delete')->with(self::identicalTo($currentUser));

        try {
            $this->processor($currentUser, $accountDeletionService)->process($this->confirmation(self::MAIN_CHARACTER_NAME), new Delete());
        } catch (\Throwable $failure) {
            self::fail(sprintf('The account is already deleted, the request must not fail: %s', $failure->getMessage()));
        }

        $warnings = array_values(array_filter(
            $this->logger->cleanLogs(),
            static fn (array $record): bool => $record[0] === LogLevel::WARNING,
        ));
        self::assertCount(1, $warnings);
        self::assertStringNotContainsString(
            self::REQUEST_JWT,
            $warnings[0][1] . json_encode($warnings[0][2], JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
    }

    public static function rejectedConfirmations(): iterable
    {
        yield 'absent or null' => [null];
        yield 'empty' => [''];
        yield 'different case' => ['kaelen vorn'];
        yield 'trailing space' => ['Kaelen Vorn '];
        yield 'name of an alt' => [self::ALT_CHARACTER_NAME];
    }

    #[DataProvider('rejectedConfirmations')]
    public function testConfirmationNotMatchingTheMainCharacterNameIsRejectedWith422AndDeletesNothing(?string $typedName): void
    {
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::never())->method('delete');

        $this->assertRejectedOnConfirmation(
            $this->processor($this->userWithMainAndAlt(), $accountDeletionService),
            $this->confirmation($typedName),
        );
        self::assertFalse($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
    }

    public function testUserWithoutMainCharacterIsRejectedWith422AndDeletesNothing(): void
    {
        $userWithoutMainCharacter = new User();
        $userWithoutMainCharacter->addCharacter((new Character())->setName(self::MAIN_CHARACTER_NAME));
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::never())->method('delete');

        $this->assertRejectedOnConfirmation(
            $this->processor($userWithoutMainCharacter, $accountDeletionService),
            $this->confirmation(self::MAIN_CHARACTER_NAME),
        );
        self::assertFalse($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
    }

    private function assertRejectedOnConfirmation(DeleteAccountProcessor $processor, DeleteAccountInput $input): void
    {
        try {
            $processor->process($input, new Delete());
            self::fail('A confirmation that does not match the main character name must be rejected');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            $violations = $exception->getConstraintViolationList();
            self::assertCount(1, $violations);
            self::assertSame('confirmation', $violations->get(0)->getPropertyPath());
        }
    }

    private function confirmation(?string $typedName): DeleteAccountInput
    {
        $input = new DeleteAccountInput();
        $input->confirmation = $typedName;

        return $input;
    }

    private function userWithMainAndAlt(): User
    {
        $mainCharacter = (new Character())->setName(self::MAIN_CHARACTER_NAME);
        $user = (new User())
            ->addCharacter($mainCharacter)
            ->addCharacter((new Character())->setName(self::ALT_CHARACTER_NAME));
        $user->setMainCharacter($mainCharacter);

        return $user;
    }

    private function processor(?User $currentUser, AccountDeletionService $accountDeletionService): DeleteAccountProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($currentUser);

        $namedArguments = [
            'security' => $security,
            'accountDeletionService' => $accountDeletionService,
            'jwtBlacklist' => $this->jwtBlacklist,
            'requestStack' => $this->requestStack,
        ];
        if ($this->processorTakesALogger()) {
            $namedArguments['logger'] = $this->logger;
        }

        return new DeleteAccountProcessor(...$namedArguments);
    }

    private function processorTakesALogger(): bool
    {
        foreach ((new \ReflectionMethod(DeleteAccountProcessor::class, '__construct'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'logger') {
                return true;
            }
        }

        return false;
    }
}
