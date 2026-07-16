<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Affiliation;
use App\Domain\JwtAlgorithm;
use App\Domain\Request\LoginRequest;
use App\Domain\Security\LoginContext;
use App\Exception\EmailNotVerifiedException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidTokenException;
use App\Exception\TokenExpiredException;
use App\Repository\RefreshTokenRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\AuthService;
use App\Support\Config;
use App\Support\ConnectionInterface;
use App\Support\Jwks;
use App\Support\JwtIssuer;
use App\Support\Queue\QueueInterface;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;

final class AuthServiceTest extends TestCase
{
    private const string JWT_SECRET = 'test_secret_key_at_least_32_characters_long_xx';
    private const string PASSWORD = 'secret-pass-1234';

    private FixedClock $clock;
    private JwtIssuer $issuer;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2026-07-05 12:00:00'));

        $config = new Config(
            appEnv: 'testing',
            appDebug: true,
            dbHost: '',
            dbPort: 3306,
            dbName: '',
            dbUser: '',
            dbPass: '',
            redisHost: '',
            redisPort: 6379,
            jwtSecret: self::JWT_SECRET,
            jwtAlgo: JwtAlgorithm::HS256,
            jwtAccessTtl: 900,
            jwtRefreshTtl: 1209600,
            emailVerifyTtl: 86400,
            appBaseUrl: 'http://localhost:9300/',
        );
        $jwtConfig = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::JWT_SECRET));
        $this->issuer = new JwtIssuer($jwtConfig, $config, $this->clock, new Jwks($config));
    }

    public function testLoginIssuesTokenPairAndUpdatesLastLogin(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->with('user@aivance.test', 'aivance')->willReturn($this->userRow());
        $users->expects(self::once())->method('updateLastLogin');

        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $tokens->expects(self::once())->method('store');

        $pair = $this->service($users, $tokens)
            ->login(new LoginRequest('user@aivance.test', self::PASSWORD, Affiliation::Aivance), $this->context());

        self::assertNotSame('', $pair->accessToken);
        self::assertNotSame('', $pair->refreshToken);
        self::assertSame(900, $pair->expiresIn);
    }

    public function testLoginWithWrongPasswordThrows(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow());
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->expectException(InvalidCredentialsException::class);
        $this->service($users, $tokens)->login(new LoginRequest('user@aivance.test', 'wrong', Affiliation::Aivance), $this->context());
    }

    public function testLoginWithUnknownEmailThrows(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn(null);
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->expectException(InvalidCredentialsException::class);
        $this->service($users, $tokens)->login(new LoginRequest('nobody@aivance.test', self::PASSWORD, Affiliation::Aivance), $this->context());
    }

    public function testLoginWithUnverifiedEmailThrows(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow(['email_verified_at' => null]));
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->expectException(EmailNotVerifiedException::class);
        $this->service($users, $tokens)->login(new LoginRequest('user@aivance.test', self::PASSWORD, Affiliation::Aivance), $this->context());
    }

    public function testLoginWithMismatchedAffiliationThrows(): void
    {
        // 이메일은 aivance 소속엔 없고 ailicet 소속에만 있는 상황 — findActiveByEmail이 조회한
        // affiliation 인자에 대해 null을 반환하도록 스텁.
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->with('user@aivance.test', 'ailicet')->willReturn(null);
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->expectException(InvalidCredentialsException::class);
        $this->service($users, $tokens)->login(new LoginRequest('user@aivance.test', self::PASSWORD, Affiliation::Ailicet), $this->context());
    }

    public function testLoginPushesSuccessEventToQueue(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow());
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->with(
            AuthService::LOGIN_EVENT_QUEUE,
            self::callback(static function (array $payload): bool {
                // 민감정보(비밀번호·토큰)는 절대 담기지 않아야 한다
                return $payload['email'] === 'user@aivance.test'
                    && $payload['ip'] === '203.0.113.7'
                    && $payload['success'] === true
                    && !isset($payload['password'])
                    && !isset($payload['access_token']);
            }),
        );

        $this->service($users, $tokens, $queue)
            ->login(new LoginRequest('user@aivance.test', self::PASSWORD, Affiliation::Aivance), $this->context());
    }

    public function testLoginPushesFailureEventToQueue(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow());
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->with(
            AuthService::LOGIN_EVENT_QUEUE,
            self::callback(static fn (array $payload): bool => $payload['success'] === false),
        );

        try {
            $this->service($users, $tokens, $queue)
                ->login(new LoginRequest('user@aivance.test', 'wrong', Affiliation::Aivance), $this->context());
            self::fail('실패한 로그인은 예외를 던져야 한다');
        } catch (InvalidCredentialsException) {
            // 기대된 예외 — 이벤트 push 검증은 위 expects() 로 수행
        }
    }

    public function testRefreshRotatesTokens(): void
    {
        $plain = 'refresh-plaintext-value';
        $tokenRow = [
            'id' => 7,
            'user_id' => 42,
            'expires_at' => '2026-07-19 12:00:00',
            'revoked_at' => null,
        ];

        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $tokens->method('findByHash')->with($this->issuer->hashRefreshToken($plain))->willReturn($tokenRow);
        $tokens->expects(self::once())->method('revoke')->with(7);
        $tokens->expects(self::once())->method('store');

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($this->userRow());

        $pair = $this->service($users, $tokens)->refresh($plain);

        self::assertNotSame('', $pair->accessToken);
        self::assertNotSame('', $pair->refreshToken);
    }

    public function testRefreshWithRevokedTokenRevokesAllAndThrows(): void
    {
        $plain = 'reused-token';
        $tokenRow = [
            'id' => 7,
            'user_id' => 42,
            'expires_at' => '2026-07-19 12:00:00',
            'revoked_at' => '2026-07-04 00:00:00',
        ];

        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $tokens->method('findByHash')->willReturn($tokenRow);
        $tokens->expects(self::once())->method('revokeAllForUser')->with(42);

        $users = $this->createMock(UserRepositoryInterface::class);

        $this->expectException(InvalidTokenException::class);
        $this->service($users, $tokens)->refresh($plain);
    }

    public function testRefreshWithExpiredTokenThrows(): void
    {
        $tokenRow = [
            'id' => 7,
            'user_id' => 42,
            'expires_at' => '2026-07-01 12:00:00', // 고정 시각(07-05) 이전
            'revoked_at' => null,
        ];

        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $tokens->method('findByHash')->willReturn($tokenRow);

        $users = $this->createMock(UserRepositoryInterface::class);

        $this->expectException(TokenExpiredException::class);
        $this->service($users, $tokens)->refresh('expired-token');
    }

    public function testRefreshWithUnknownTokenThrows(): void
    {
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $tokens->method('findByHash')->willReturn(null);

        $users = $this->createMock(UserRepositoryInterface::class);

        $this->expectException(InvalidTokenException::class);
        $this->service($users, $tokens)->refresh('unknown-token');
    }

    public function testLogoutRevokesActiveToken(): void
    {
        $tokenRow = [
            'id' => 7,
            'user_id' => 42,
            'expires_at' => '2026-07-19 12:00:00',
            'revoked_at' => null,
        ];

        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $tokens->method('findByHash')->willReturn($tokenRow);
        $tokens->expects(self::once())->method('revoke')->with(7);

        $users = $this->createMock(UserRepositoryInterface::class);

        $this->service($users, $tokens)->logout('some-token');
    }

    private function service(
        UserRepositoryInterface $users,
        RefreshTokenRepositoryInterface $tokens,
        ?QueueInterface $queue = null,
    ): AuthService {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(static fn (callable $work): mixed => $work());

        return new AuthService(
            $users,
            $tokens,
            $this->issuer,
            $this->clock,
            $db,
            $queue ?? $this->createMock(QueueInterface::class),
        );
    }

    private function context(): LoginContext
    {
        return new LoginContext('203.0.113.7', 'TestAgent/1.0');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function userRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'email' => 'user@aivance.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'affiliation' => 'aivance',
            'role' => 3,
            'is_active' => 1,
            'email_verified_at' => '2026-07-01 00:00:00',
        ], $overrides);
    }
}
