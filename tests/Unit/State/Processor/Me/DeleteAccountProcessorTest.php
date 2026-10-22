<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Me;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Me\UserResource;
use App\Entity\User;
use App\Security\JwtBlacklist;
use App\Service\Account\AccountDeletionService;
use App\State\Processor\Me\DeleteAccountProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
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
        $currentUser = new User();
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::once())->method('delete')->with(self::identicalTo($currentUser));

        $result = $this->processor($currentUser, $accountDeletionService)->process(new UserResource(), new Delete());

        self::assertNull($result);
        self::assertTrue($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
        self::assertFalse($this->jwtBlacklist->isBlocked(self::OTHER_JWT));
    }

    public function testJwtOfTheRequestIsNotBlockedWhenTheDeletionFails(): void
    {
        $accountDeletionService = $this->createStub(AccountDeletionService::class);
        $accountDeletionService->method('delete')->willThrowException(new \RuntimeException('deletion failed'));
        $processor = $this->processor(new User(), $accountDeletionService);

        try {
            $processor->process(new UserResource(), new Delete());
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
            $processor->process(new UserResource(), new Delete());
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
        $currentUser = new User();
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::once())->method('delete')->with(self::identicalTo($currentUser));

        try {
            $this->processor($currentUser, $accountDeletionService)->process(new UserResource(), new Delete());
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
