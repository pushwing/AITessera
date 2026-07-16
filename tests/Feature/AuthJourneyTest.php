<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Service\UserService;
use App\Support\AppFactory;
use App\Support\ConnectionInterface;
use App\Support\ContainerFactory;
use App\Support\Queue\QueueInterface;
use Dotenv\Dotenv;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * QC E2E 시나리오 테스트 — 인증 여정 전 구간(이슈 #26).
 *
 * 실제 미들웨어 파이프라인 + 실 DB + 인메모리 큐를 관통시켜 다음을 검증한다.
 *   기본 시나리오: 회원가입 → 이메일 인증 → 로그인 → /me 조회 → refresh 회전 재발급
 *   추가 시나리오: 미인증 로그인·자격증명 오류·중복가입·유효성 실패·인증토큰 재사용·
 *                  refresh 회전 후 옛 토큰 재사용(탈취 감지)·로그아웃 무효화·인증메일 재발송
 *
 * 이메일 인증 토큰은 회원가입 시 `mail_queue`(InMemoryQueue, 컨테이너 싱글턴)에 적재되므로
 * 테스트에서 큐를 pop 해 원본 토큰을 획득한다(실제 메일 발송 없이 인증 흐름을 완결).
 *
 * DB 격리: 앱 코드가 자체 트랜잭션을 열어 outer-rollback 이 불가하므로, 매 테스트 고유
 * 이메일로 데이터를 만들고 tearDown 에서 생성 사용자 행을 삭제한다(자식 테이블은 FK CASCADE).
 * DB 가 없으면(로컬 무-DB 등) 스킵한다.
 */
final class AuthJourneyTest extends TestCase
{
    private const string JWT_SECRET = 'test_secret_key_at_least_32_characters_long_xx';
    private const string PASSWORD = 'Str0ng!Passw0rd';

    private ContainerInterface $container;
    private PDO $pdo;

    /** @var list<string> 정리 대상 이메일(생성된 사용자) */
    private array $createdEmails = [];

    /**
     * setUp 에서 덮어쓴 $_ENV 키의 원래 값 — tearDown 에서 복원해 다른 테스트로의 누출을 막는다.
     *
     * @var array<string, string|null>
     */
    private array $envBackup = [];

    protected function setUp(): void
    {
        // DB 접속정보는 .env 에서, 그 외 실행 파라미터는 명시적으로 주입한다.
        $root = dirname(__DIR__, 2);
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        // .env 의 APP_ENV=local 을 덮어써 테스트 구성(InMemory 큐·레이트리밋)을 강제한다.
        // 인증 엔드포인트 반복 호출이 레이트리밋(기본 10/분)에 걸리지 않도록 넉넉히 완화한다.
        // $_ENV 는 프로세스 전역이라 다른 테스트로 새지 않도록 원값을 백업 후 tearDown 에서 복원한다.
        $this->overrideEnv([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true',
            'JWT_SECRET' => self::JWT_SECRET,
            'RATE_LIMIT_AUTH' => '1000',
        ]);

        $this->container = ContainerFactory::build();

        try {
            $this->pdo = $this->container->get(ConnectionInterface::class)->pdo();
            $this->pdo->query('SELECT 1');
        } catch (Throwable $e) {
            self::markTestSkipped('DB 미가용 — E2E 시나리오 스킵: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            // 자식 테이블(refresh_tokens·email_verifications)은 FK ON DELETE CASCADE 로 함께 삭제된다.
            $stmt = $this->pdo->prepare('DELETE FROM users WHERE email = ?');
            foreach ($this->createdEmails as $email) {
                $stmt->execute([$email]);
            }
        }

        // 덮어썼던 $_ENV 를 원상복구 — 후속 테스트(예: PipelineTest 레이트리밋)에 영향 주지 않도록.
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * $_ENV 키들을 덮어쓰되, 각 키의 원래 값을 백업한다(문자열이 아니면 null 로 간주해 복원 시 unset).
     *
     * @param array<string, string> $overrides
     */
    private function overrideEnv(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            $original = $_ENV[$key] ?? null;
            $this->envBackup[$key] = is_string($original) ? $original : null;
            $_ENV[$key] = $value;
        }
    }

    // ── 기본 시나리오 ──────────────────────────────────────────────────────

    public function testFullJourney_register_verify_login_me_refresh(): void
    {
        // 1) 회원가입 — 이메일 미인증 상태로 생성(201)
        [$email, $userId, $verifyToken] = $this->register();
        self::assertGreaterThan(0, $userId);

        // 2) 이메일 인증 — 큐에서 꺼낸 토큰으로 활성화(200)
        $verify = $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyToken]);
        self::assertSame(200, $verify->getStatusCode());
        self::assertTrue($this->decode($verify)['data']['verified']);

        // 3) 로그인 — Access/Refresh 토큰 쌍 발급(201)
        $login = $this->login($email);
        self::assertSame(201, $login->getStatusCode());
        $tokens = $this->decode($login)['data'];
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertNotEmpty($tokens['access_token']);
        self::assertNotEmpty($tokens['refresh_token']);

        // 4) /me — Access 토큰으로 내 프로필 조회(200)
        $me = $this->handle('GET', '/api/v1/me', $this->bearer($tokens['access_token']));
        self::assertSame(200, $me->getStatusCode());
        $profile = $this->decode($me)['data'];
        self::assertSame($userId, $profile['id']);
        self::assertSame($email, $profile['email']);
        self::assertTrue($profile['email_verified']);

        // 5) refresh 회전 — 새 토큰 쌍 발급(200), 이전 값과 달라야 한다
        $refresh = $this->handle('POST', '/api/v1/tokens/refresh', [], ['refresh_token' => $tokens['refresh_token']]);
        self::assertSame(200, $refresh->getStatusCode());
        $rotated = $this->decode($refresh)['data'];
        self::assertNotEmpty($rotated['access_token']);
        self::assertNotSame($tokens['refresh_token'], $rotated['refresh_token'], '회전 시 refresh 토큰이 교체되어야 한다');

        // 6) 회전된 Access 토큰도 /me 를 통과한다(200)
        $me2 = $this->handle('GET', '/api/v1/me', $this->bearer($rotated['access_token']));
        self::assertSame(200, $me2->getStatusCode());
        self::assertSame($userId, $this->decode($me2)['data']['id']);
    }

    // ── 추가 시나리오 (핵심 negative) ─────────────────────────────────────

    public function testLoginBeforeVerificationReturns403(): void
    {
        // 인증 전 로그인은 EMAIL_NOT_VERIFIED(403)
        [$email] = $this->register();

        $response = $this->login($email);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('EMAIL_NOT_VERIFIED', $this->decode($response)['code']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        [$email, , $verifyToken] = $this->register();
        $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyToken]);

        $response = $this->login($email, 'Wr0ng!Passw0rd');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('INVALID_CREDENTIALS', $this->decode($response)['code']);
    }

    public function testDuplicateRegistrationReturns409(): void
    {
        [$email] = $this->register();

        // 같은 이메일 재가입 → ALREADY_EXISTS(409)
        $response = $this->handle('POST', '/api/v1/users', [], $this->registerPayload($email));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('ALREADY_EXISTS', $this->decode($response)['code']);
    }

    public function testRegisterWithWeakPasswordReturns422(): void
    {
        $payload = $this->registerPayload('e2e-weak-' . bin2hex(random_bytes(4)) . '@aivance.test');
        $payload['password'] = 'weak'; // 정책 위반(10자 미만·특수문자 없음)

        $response = $this->handle('POST', '/api/v1/users', [], $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($response)['code']);
    }

    public function testRegisterWithoutTermsAgreedReturns422(): void
    {
        $payload = $this->registerPayload('e2e-terms-' . bin2hex(random_bytes(4)) . '@aivance.test');
        $payload['terms_agreed'] = false; // 필수 약관 미동의

        $response = $this->handle('POST', '/api/v1/users', [], $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($response)['code']);
    }

    public function testEmailVerificationTokenCannotBeReused(): void
    {
        [, , $verifyToken] = $this->register();

        // 1회차 인증 성공
        $first = $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyToken]);
        self::assertSame(200, $first->getStatusCode());

        // 2회차 재사용 → 이미 소비된 토큰(INVALID_TOKEN, 401)
        $second = $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyToken]);
        self::assertSame(401, $second->getStatusCode());
        self::assertSame('INVALID_TOKEN', $this->decode($second)['code']);
    }

    public function testRefreshRotationDetectsReuseOfOldToken(): void
    {
        $tokens = $this->registerVerifyLogin();

        // 정상 회전 — 옛 토큰 폐기, 새 토큰 발급
        $rotated = $this->handle('POST', '/api/v1/tokens/refresh', [], ['refresh_token' => $tokens['refresh_token']]);
        self::assertSame(200, $rotated->getStatusCode());

        // 폐기된 옛 토큰 재사용 → 탈취 간주(INVALID_TOKEN, 401)
        $reuse = $this->handle('POST', '/api/v1/tokens/refresh', [], ['refresh_token' => $tokens['refresh_token']]);
        self::assertSame(401, $reuse->getStatusCode());
        self::assertSame('INVALID_TOKEN', $this->decode($reuse)['code']);

        // 재사용 감지 시 사용자 전체 세션 무효화 → 방금 발급된 새 토큰도 사용 불가
        $newToken = $this->decode($rotated)['data']['refresh_token'];
        $afterInvalidation = $this->handle('POST', '/api/v1/tokens/refresh', [], ['refresh_token' => $newToken]);
        self::assertSame(401, $afterInvalidation->getStatusCode());
    }

    public function testLogoutRevokesRefreshToken(): void
    {
        $tokens = $this->registerVerifyLogin();

        // 로그아웃 — Refresh 무효화(204, 본문 없음)
        $logout = $this->handle('DELETE', '/api/v1/tokens', [], ['refresh_token' => $tokens['refresh_token']]);
        self::assertSame(204, $logout->getStatusCode());

        // 무효화된 토큰으로 재발급 시도 → INVALID_TOKEN(401)
        $refresh = $this->handle('POST', '/api/v1/tokens/refresh', [], ['refresh_token' => $tokens['refresh_token']]);
        self::assertSame(401, $refresh->getStatusCode());
        self::assertSame('INVALID_TOKEN', $this->decode($refresh)['code']);
    }

    public function testResendVerificationAlwaysAccepts(): void
    {
        // 미가입 이메일 — 존재 여부를 노출하지 않으려 항상 202
        $unknown = $this->handle('POST', '/api/v1/users/verify/resend', [], [
            'email' => 'nobody-' . bin2hex(random_bytes(4)) . '@aivance.test',
            'affiliation' => 'aivance',
        ]);
        self::assertSame(202, $unknown->getStatusCode());

        // 가입했으나 미인증인 이메일 — 새 토큰을 큐에 적재하고 202
        [$email] = $this->register();
        $resend = $this->handle('POST', '/api/v1/users/verify/resend', [], ['email' => $email, 'affiliation' => 'aivance']);
        self::assertSame(202, $resend->getStatusCode());
    }

    public function testSameEmailCanRegisterAndLoginToTwoDifferentAffiliations(): void
    {
        $email = 'e2e-multi-' . bin2hex(random_bytes(6)) . '@aivance.test';
        $this->createdEmails[] = $email;

        // 1) aivance 소속으로 가입·인증·로그인
        $registerAivance = $this->handle('POST', '/api/v1/users', [], $this->registerPayload($email));
        self::assertSame(201, $registerAivance->getStatusCode(), (string) $registerAivance->getBody());
        $verifyTokenAivance = $this->popVerificationToken();
        $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyTokenAivance]);

        $loginAivance = $this->login($email, null, 'aivance');
        self::assertSame(201, $loginAivance->getStatusCode());

        // 2) 같은 이메일로 ailicet 소속에도 독립적으로 가입 가능해야 한다(409가 아니어야 함)
        $ailicetPayload = $this->registerPayload($email);
        $ailicetPayload['affiliation'] = 'ailicet';
        $registerAilicet = $this->handle('POST', '/api/v1/users', [], $ailicetPayload);
        self::assertSame(201, $registerAilicet->getStatusCode(), (string) $registerAilicet->getBody());

        // 3) ailicet 계정은 아직 이메일 미인증 — ailicet 소속으로 로그인하면 403(EMAIL_NOT_VERIFIED)
        $loginAilicetBeforeVerify = $this->login($email, null, 'ailicet');
        self::assertSame(403, $loginAilicetBeforeVerify->getStatusCode());
        self::assertSame('EMAIL_NOT_VERIFIED', $this->decode($loginAilicetBeforeVerify)['code']);

        // 4) ailicet 인증 후 로그인 성공 — aivance 로그인과 별개의 토큰이 발급된다
        $verifyTokenAilicet = $this->popVerificationToken();
        $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyTokenAilicet]);
        $loginAilicet = $this->login($email, null, 'ailicet');
        self::assertSame(201, $loginAilicet->getStatusCode());
        self::assertNotSame(
            $this->decode($loginAivance)['data']['access_token'],
            $this->decode($loginAilicet)['data']['access_token'],
        );
    }

    public function testLoginWithAffiliationTheEmailIsNotRegisteredInReturns401(): void
    {
        // aivance로만 가입·인증했는데 ailicet 소속으로 로그인 시도 → INVALID_CREDENTIALS(계정 존재 노출 안 함)
        $tokens = $this->registerVerifyLogin();
        self::assertNotEmpty($tokens['access_token']);

        [$email] = [$this->createdEmails[array_key_last($this->createdEmails)]];
        $response = $this->login($email, null, 'ailicet');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('INVALID_CREDENTIALS', $this->decode($response)['code']);
    }

    // ── 시나리오 헬퍼 ─────────────────────────────────────────────────────

    /**
     * 회원가입 후 [이메일, 사용자 id, 이메일 인증 토큰]을 반환한다.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private function register(): array
    {
        $email = 'e2e-' . bin2hex(random_bytes(6)) . '@aivance.test';
        $this->createdEmails[] = $email;

        $response = $this->handle('POST', '/api/v1/users', [], $this->registerPayload($email));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $userId = (int) $this->decode($response)['data']['id'];

        return [$email, $userId, $this->popVerificationToken()];
    }

    /**
     * 가입 → 인증 → 로그인까지 마친 토큰 쌍을 반환한다.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    private function registerVerifyLogin(): array
    {
        [$email, , $verifyToken] = $this->register();
        $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyToken]);

        /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $data */
        $data = $this->decode($this->login($email))['data'];

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function registerPayload(string $email): array
    {
        // aivance 소속은 profile 부가 스키마가 비어 있어 가장 단순하다.
        return [
            'email' => $email,
            'password' => self::PASSWORD,
            'affiliation' => 'aivance',
            'name' => 'QC테스터',
            'contact' => '010-1234-5678',
            'company' => null,
            'profile' => [],
            'terms_agreed' => true,
            'third_party_agreed' => true,
        ];
    }

    private function login(string $email, ?string $password = null, string $affiliation = 'aivance'): ResponseInterface
    {
        return $this->handle('POST', '/api/v1/tokens', [], [
            'email' => $email,
            'password' => $password ?? self::PASSWORD,
            'affiliation' => $affiliation,
        ]);
    }

    /**
     * 회원가입 시 mail_queue 에 적재된 원본 이메일 인증 토큰을 꺼낸다.
     */
    private function popVerificationToken(): string
    {
        /** @var QueueInterface $queue */
        $queue = $this->container->get(QueueInterface::class);
        $message = $queue->pop(UserService::MAIL_QUEUE);

        self::assertNotNull($message, '인증 메일이 큐에 적재되어야 한다');
        self::assertSame('email_verification', $message['type']);
        self::assertIsString($message['token']);

        return $message['token'];
    }

    // ── 파이프라인 실행 유틸 ───────────────────────────────────────────────

    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $json
     */
    private function handle(string $method, string $path, array $headers = [], ?array $json = null): ResponseInterface
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($psr17->createStream(json_encode($json, JSON_THROW_ON_ERROR)));
        }

        return AppFactory::pipeline($this->container)->handle($request);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $accessToken): array
    {
        return ['Authorization' => "Bearer {$accessToken}"];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
