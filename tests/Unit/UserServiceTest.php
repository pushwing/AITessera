<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Affiliation;
use App\Domain\JwtAlgorithm;
use App\Domain\Request\CreateOperatorRequest;
use App\Domain\Request\RegisterRequest;
use App\Domain\Request\UpdateMeRequest;
use App\Domain\UserRole;
use App\Exception\AlreadyExistsException;
use App\Exception\ForbiddenException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidTokenException;
use App\Exception\NotFoundException;
use App\Exception\TokenExpiredException;
use App\Repository\EmailVerificationRepositoryInterface;
use App\Repository\RefreshTokenRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\UserService;
use App\Support\Config;
use App\Support\ConnectionInterface;
use App\Support\Queue\QueueInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;

final class UserServiceTest extends TestCase
{
    private FixedClock $clock;
    private Config $config;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2026-07-05 12:00:00'));
        $this->config = new Config(
            appEnv: 'testing',
            appDebug: true,
            dbHost: '',
            dbPort: 3306,
            dbName: '',
            dbUser: '',
            dbPass: '',
            redisHost: '',
            redisPort: 6379,
            jwtSecret: 'test_secret_key_at_least_32_characters_long_xx',
            jwtAlgo: JwtAlgorithm::HS256,
            jwtAccessTtl: 900,
            jwtRefreshTtl: 1209600,
            emailVerifyTtl: 86400,
            appBaseUrl: 'http://localhost:9300/',
        );
    }

    public function testMeReturnsProfileWithoutSensitiveData(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findProfileById')->with(42)->willReturn([
            'id' => 42,
            'email' => 'user@aivance.test',
            'name' => '홍길동',
            'affiliation' => 'aivance',
            'role' => 3,
            'contact' => '010-1234-5678',
            'company' => 'AIvance',
            'profile' => '{"team":"ops"}',
            'email_verified_at' => '2026-07-01 00:00:00',
            'created_at' => '2026-07-01 09:00:00',
        ]);

        $profile = $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->me(42);

        self::assertSame('user@aivance.test', $profile->email);
        self::assertSame(Affiliation::Aivance, $profile->affiliation);
        self::assertSame(['team' => 'ops'], $profile->profile);
        self::assertTrue($profile->toArray()['email_verified']);
        self::assertArrayNotHasKey('password_hash', $profile->toArray());
    }

    public function testMeThrowsNotFoundForUnknownUser(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findProfileById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->me(999);
    }

    public function testUpdateMeChangesProfileFieldsAndReturnsFreshProfile(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(42)->willReturn($this->userRow(['id' => 42]));
        $users->expects(self::once())->method('updateFields')
            ->with(42, self::callback(
                static fn (array $f): bool => $f['name'] === '새이름' && $f['contact'] === '010-9999-8888',
            ));
        $users->expects(self::never())->method('updatePassword');
        // 수정 후 최신 프로필 조회
        $users->method('findProfileById')->with(42)->willReturn($this->profileRow(['id' => 42, 'name' => '새이름']));

        $profile = $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->updateMe(UpdateMeRequest::fromArray(['name' => '새이름', 'contact' => '010-9999-8888']), 42);

        self::assertSame('새이름', $profile->name);
    }

    public function testUpdateMeChangesPasswordWhenCurrentMatches(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(42)->willReturn(
            $this->userRow(['id' => 42, 'password_hash' => password_hash('Old!Passw0rd', PASSWORD_ARGON2ID)]),
        );
        $users->expects(self::once())->method('updatePassword')->with(42);
        $users->method('findProfileById')->willReturn($this->profileRow(['id' => 42]));

        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->updateMe(UpdateMeRequest::fromArray([
                'password' => 'New!Passw0rd1',
                'current_password' => 'Old!Passw0rd',
            ]), 42);
    }

    public function testUpdateMeRejectsWrongCurrentPassword(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(42)->willReturn(
            $this->userRow(['id' => 42, 'password_hash' => password_hash('Old!Passw0rd', PASSWORD_ARGON2ID)]),
        );
        $users->expects(self::never())->method('updatePassword');
        $users->expects(self::never())->method('updateFields');

        $this->expectException(InvalidCredentialsException::class);
        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->updateMe(UpdateMeRequest::fromArray([
                'password' => 'New!Passw0rd1',
                'current_password' => 'Wr0ng!Passw0rd',
            ]), 42);
    }

    public function testUpdateMeThrowsNotFoundForUnknownUser(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->updateMe(UpdateMeRequest::fromArray(['name' => '홍길동']), 999);
    }

    public function testWithdrawSoftDeletesAndRevokesAllTokens(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(42)->willReturn(
            $this->userRow(['id' => 42, 'password_hash' => password_hash('My!Passw0rd', PASSWORD_ARGON2ID)]),
        );
        $users->expects(self::once())->method('softDelete')->with(42);

        $refreshTokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refreshTokens->expects(self::once())->method('revokeAllForUser')->with(42);

        $this->service(
            $users,
            $this->createMock(EmailVerificationRepositoryInterface::class),
            $this->createMock(QueueInterface::class),
            $refreshTokens,
        )->withdraw(42, 'My!Passw0rd');
    }

    public function testWithdrawRejectsWrongPassword(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(42)->willReturn(
            $this->userRow(['id' => 42, 'password_hash' => password_hash('My!Passw0rd', PASSWORD_ARGON2ID)]),
        );
        $users->expects(self::never())->method('softDelete');

        $refreshTokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refreshTokens->expects(self::never())->method('revokeAllForUser');

        $this->expectException(InvalidCredentialsException::class);
        $this->service(
            $users,
            $this->createMock(EmailVerificationRepositoryInterface::class),
            $this->createMock(QueueInterface::class),
            $refreshTokens,
        )->withdraw(42, 'Wr0ng!Passw0rd');
    }

    public function testRegisterCreatesUserAndEnqueuesVerification(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('emailExists')->willReturn(false);
        $users->expects(self::once())->method('create')->willReturn(100);

        $verifications = $this->createMock(EmailVerificationRepositoryInterface::class);
        $verifications->expects(self::once())->method('store');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')
            ->with(UserService::MAIL_QUEUE, self::callback(
                static fn (array $p): bool => $p['type'] === 'email_verification'
                    && $p['to'] === 'user@aivance.test'
                    && is_string($p['token']) && $p['token'] !== '',
            ));

        $userId = $this->service($users, $verifications, $queue)->register($this->registerRequest());

        self::assertSame(100, $userId);
    }

    public function testRegisterWithDuplicateEmailThrows(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('emailExists')->willReturn(true);
        $users->expects(self::never())->method('create');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $this->expectException(AlreadyExistsException::class);
        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $queue)
            ->register($this->registerRequest());
    }

    public function testCreateOperatorAccountCreatesRoleAndMarksVerified(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        // 요청 운영자(생성자) — 소속 aivance
        $users->method('findById')->with(100)->willReturn($this->userRow(['role' => 1]));
        $users->method('emailExists')->willReturn(false);
        $users->expects(self::once())->method('create')->willReturn(200);
        // 즉시 인증 완료 처리
        $users->expects(self::once())->method('markEmailVerified')->with(200);

        // 운영자 계정 생성 흐름에서는 인증 메일을 큐에 넣지 않는다.
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $newId = $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $queue)
            ->createOperatorAccount($this->createOperatorRequest(), 100);

        self::assertSame(200, $newId);
    }

    public function testCreateOperatorAccountRejectsDifferentAffiliation(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        // 생성자 소속은 aicura 인데 요청 소속은 aivance → 소속 불일치
        $users->method('findById')->with(100)->willReturn($this->userRow(['role' => 1, 'affiliation' => 'aicura']));
        $users->expects(self::never())->method('create');

        $this->expectException(ForbiddenException::class);
        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->createOperatorAccount($this->createOperatorRequest(), 100);
    }

    public function testCreateOperatorAccountRejectsInactiveCreator(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        // 정지된 운영자(is_active=0) — 토큰이 아직 유효해도 계정 생성 불가
        $users->method('findById')->with(100)->willReturn($this->userRow(['role' => 1, 'is_active' => 0]));
        $users->expects(self::never())->method('create');

        $this->expectException(ForbiddenException::class);
        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->createOperatorAccount($this->createOperatorRequest(), 100);
    }

    public function testCreateOperatorAccountRejectsDuplicateEmail(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(100)->willReturn($this->userRow(['role' => 1]));
        $users->method('emailExists')->willReturn(true);
        $users->expects(self::never())->method('create');

        $this->expectException(AlreadyExistsException::class);
        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $this->createMock(QueueInterface::class))
            ->createOperatorAccount($this->createOperatorRequest(), 100);
    }

    public function testVerifyEmailMarksUserVerifiedAndConsumesToken(): void
    {
        $verifications = $this->createMock(EmailVerificationRepositoryInterface::class);
        $verifications->method('findByHash')->willReturn([
            'id' => 5,
            'user_id' => 100,
            'expires_at' => '2026-07-06 12:00:00',
            'consumed_at' => null,
        ]);
        $verifications->expects(self::once())->method('consume')->with(5);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('markEmailVerified')->with(100);

        $this->service($users, $verifications, $this->createMock(QueueInterface::class))
            ->verifyEmail('some-token');
    }

    public function testVerifyEmailWithConsumedTokenThrows(): void
    {
        $verifications = $this->createMock(EmailVerificationRepositoryInterface::class);
        $verifications->method('findByHash')->willReturn([
            'id' => 5,
            'user_id' => 100,
            'expires_at' => '2026-07-06 12:00:00',
            'consumed_at' => '2026-07-05 00:00:00',
        ]);

        $this->expectException(InvalidTokenException::class);
        $this->serviceWithMocks()->verifyEmail('used-token');
    }

    public function testVerifyEmailWithExpiredTokenThrows(): void
    {
        $verifications = $this->createMock(EmailVerificationRepositoryInterface::class);
        $verifications->method('findByHash')->willReturn([
            'id' => 5,
            'user_id' => 100,
            'expires_at' => '2026-07-01 12:00:00',
            'consumed_at' => null,
        ]);

        $this->expectException(TokenExpiredException::class);
        $this->service(
            $this->createMock(UserRepositoryInterface::class),
            $verifications,
            $this->createMock(QueueInterface::class),
        )->verifyEmail('expired-token');
    }

    public function testVerifyEmailWithUnknownTokenThrows(): void
    {
        $verifications = $this->createMock(EmailVerificationRepositoryInterface::class);
        $verifications->method('findByHash')->willReturn(null);

        $this->expectException(InvalidTokenException::class);
        $this->service(
            $this->createMock(UserRepositoryInterface::class),
            $verifications,
            $this->createMock(QueueInterface::class),
        )->verifyEmail('unknown-token');
    }

    public function testResendReissuesForUnverifiedUser(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow(['email_verified_at' => null]));

        $verifications = $this->createMock(EmailVerificationRepositoryInterface::class);
        $verifications->expects(self::once())->method('deleteUnconsumedForUser')->with(100);
        $verifications->expects(self::once())->method('store');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push');

        $this->service($users, $verifications, $queue)->resendVerification('user@aivance.test');
    }

    public function testResendIsNoopForVerifiedUser(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow(['email_verified_at' => '2026-07-01 00:00:00']));

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $queue)
            ->resendVerification('user@aivance.test');
    }

    public function testResendIsNoopForUnknownEmail(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn(null);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $queue)
            ->resendVerification('nobody@aivance.test');
    }

    private function serviceWithMocks(): UserService
    {
        return $this->service(
            $this->createMock(UserRepositoryInterface::class),
            $this->createMock(EmailVerificationRepositoryInterface::class),
            $this->createMock(QueueInterface::class),
        );
    }

    private function service(
        UserRepositoryInterface $users,
        EmailVerificationRepositoryInterface $verifications,
        QueueInterface $queue,
        ?RefreshTokenRepositoryInterface $refreshTokens = null,
    ): UserService {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(static fn (callable $work): mixed => $work());

        return new UserService(
            $users,
            $verifications,
            $refreshTokens ?? $this->createMock(RefreshTokenRepositoryInterface::class),
            $queue,
            $this->config,
            $this->clock,
            $db,
        );
    }

    private function registerRequest(): RegisterRequest
    {
        return new RegisterRequest(
            email: 'user@aivance.test',
            password: 'password1234!',
            affiliation: Affiliation::Aivance,
            name: '홍길동',
            contact: '010-1234-5678',
            company: null,
            profile: [],
        );
    }

    private function createOperatorRequest(): CreateOperatorRequest
    {
        return new CreateOperatorRequest(
            email: 'agency@aivance.test',
            password: 'password1234!',
            role: UserRole::Agency,
            affiliation: Affiliation::Aivance,
            name: '대행사담당자',
            contact: '010-1234-5678',
            company: null,
            profile: [],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function userRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 100,
            'email' => 'user@aivance.test',
            'password_hash' => 'x',
            'affiliation' => 'aivance',
            'role' => 3,
            'is_active' => 1,
            'email_verified_at' => null,
        ], $overrides);
    }

    /**
     * findProfileById 반환 형태(민감정보 제외 컬럼셋).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function profileRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 100,
            'email' => 'user@aivance.test',
            'name' => '홍길동',
            'affiliation' => 'aivance',
            'role' => 3,
            'contact' => '010-1234-5678',
            'company' => 'AIvance',
            'profile' => null,
            'email_verified_at' => '2026-07-01 00:00:00',
            'created_at' => '2026-07-01 09:00:00',
        ], $overrides);
    }
}
