<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AppFactory;
use App\Support\ConnectionInterface;
use App\Support\ContainerFactory;
use Dotenv\Dotenv;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * QC E2E — 운영자 전용 계정 생성(이슈 #29).
 *
 * 실제 미들웨어 파이프라인 + 실 DB 를 관통시켜 회원구분(role) 기반 인가를 검증한다.
 *   - 운영자 토큰으로 대행사 계정 생성(201) → 생성 계정은 즉시 로그인 가능하고 role=2 로 노출
 *   - 일반회원 토큰 → 403(FORBIDDEN)
 *   - 토큰 없음 → 401(UNAUTHORIZED)
 *   - 타 소속 계정 생성 시도 → 403(FORBIDDEN)
 *   - 일반회원(3) 생성 시도 → 422(VALIDATION_ERROR)
 *
 * DB 가 없으면 스킵한다. 생성 사용자는 tearDown 에서 이메일로 삭제한다(자식 테이블 FK CASCADE).
 */
final class OperatorJourneyTest extends TestCase
{
    private const string JWT_SECRET = 'test_secret_key_at_least_32_characters_long_xx';
    private const string PASSWORD = 'Str0ng!Passw0rd';

    private ContainerInterface $container;
    private PDO $pdo;

    /** @var list<string> */
    private array $createdEmails = [];

    /** @var array<string, string|null> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        $this->overrideEnv([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true',
            'JWT_SECRET' => self::JWT_SECRET,
            'RATE_LIMIT_AUTH' => '1000',
            'RATE_LIMIT_API' => '1000',
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
            $stmt = $this->pdo->prepare('DELETE FROM users WHERE email = ?');
            foreach ($this->createdEmails as $email) {
                $stmt->execute([$email]);
            }
        }

        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testOperatorCreatesAgencyAccountThatCanLogin(): void
    {
        $operatorToken = $this->loginToken($this->seedUser('aivance', 1));

        $agencyEmail = $this->uniqueEmail('agency');
        $response = $this->handle('POST', '/api/v1/operators', $this->bearer($operatorToken), $this->createPayload($agencyEmail, 2, 'aivance'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->decode($response)['data'];
        self::assertSame(2, $data['role']);
        self::assertTrue($data['email_verified']);

        // 생성 계정은 이메일 인증이 즉시 완료되어 곧바로 로그인 가능하다.
        $login = $this->login($agencyEmail);
        self::assertSame(201, $login->getStatusCode());
        $accessToken = $this->decode($login)['data']['access_token'];

        // /me 응답에 role=2 가 노출된다.
        $me = $this->handle('GET', '/api/v1/me', $this->bearer($accessToken));
        self::assertSame(200, $me->getStatusCode());
        self::assertSame(2, $this->decode($me)['data']['role']);
    }

    public function testMemberTokenIsForbidden(): void
    {
        $memberToken = $this->loginToken($this->seedUser('aivance', 3));

        $response = $this->handle('POST', '/api/v1/operators', $this->bearer($memberToken), $this->createPayload($this->uniqueEmail('x'), 2, 'aivance'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('FORBIDDEN', $this->decode($response)['code']);
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $response = $this->handle('POST', '/api/v1/operators', [], $this->createPayload($this->uniqueEmail('x'), 2, 'aivance'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', $this->decode($response)['code']);
    }

    public function testOperatorCannotCreateAccountInOtherAffiliation(): void
    {
        $operatorToken = $this->loginToken($this->seedUser('aivance', 1));

        // aivance 운영자가 aicura 계정 생성 시도 → 소속 불일치(403)
        $response = $this->handle('POST', '/api/v1/operators', $this->bearer($operatorToken), $this->createPayload($this->uniqueEmail('cross'), 2, 'aicura'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('FORBIDDEN', $this->decode($response)['code']);
    }

    public function testCannotCreateMemberRoleViaOperatorEndpoint(): void
    {
        $operatorToken = $this->loginToken($this->seedUser('aivance', 1));

        // 일반회원(3) 생성 시도 → 검증 실패(422)
        $response = $this->handle('POST', '/api/v1/operators', $this->bearer($operatorToken), $this->createPayload($this->uniqueEmail('member'), 3, 'aivance'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($response)['code']);
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────

    /**
     * 이메일 인증 완료·활성 상태의 사용자를 DB 에 직접 삽입하고 이메일을 반환한다.
     */
    private function seedUser(string $affiliation, int $role): string
    {
        $email = $this->uniqueEmail('seed');
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users
                (email, password_hash, affiliation, role, name, contact,
                 email_verified_at, terms_agreed_at, third_party_agreed_at, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
        );
        $stmt->execute([
            $email,
            password_hash(self::PASSWORD, PASSWORD_ARGON2ID),
            $affiliation,
            $role,
            '테스트',
            '010-0000-0000',
            $now,
            $now,
            $now,
            $now,
        ]);

        return $email;
    }

    private function loginToken(string $email): string
    {
        $login = $this->login($email);
        self::assertSame(201, $login->getStatusCode(), (string) $login->getBody());

        return (string) $this->decode($login)['data']['access_token'];
    }

    private function login(string $email): ResponseInterface
    {
        return $this->handle('POST', '/api/v1/tokens', [], [
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(string $email, int $role, string $affiliation): array
    {
        return [
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'affiliation' => $affiliation,
            'name' => '신규계정',
            'contact' => '010-1234-5678',
            'company' => null,
            'profile' => [],
        ];
    }

    private function uniqueEmail(string $prefix): string
    {
        $email = "op-{$prefix}-" . bin2hex(random_bytes(5)) . '@aivance.test';
        $this->createdEmails[] = $email;

        return $email;
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

    /**
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
}
