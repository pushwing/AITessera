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
 * QC E2E — 본인 정보 수정·회원 탈퇴 API(이슈 #39).
 *
 * 실제 미들웨어 파이프라인 + 실 DB 를 관통시켜 본인 확인·부분수정·탈퇴 흐름을 검증한다.
 *   - 수정: 이름·연락처·회사 부분 수정 반영
 *   - 비밀번호 변경: 현재 비밀번호 확인(불일치 시 401), 변경 후 새 비밀번호로 로그인
 *   - 탈퇴: 비밀번호 확인(불일치 시 401), 탈퇴 후 로그인 불가, 동일 이메일 재가입 가능
 *
 * DB 가 없으면 스킵한다. 생성 사용자는 tearDown 에서 이메일로 삭제한다.
 */
final class MeJourneyTest extends TestCase
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

    public function testUpdatesOwnProfileFields(): void
    {
        $email = $this->seedUser('aivance');
        $token = $this->loginToken($email);

        $response = $this->handle('PATCH', '/api/v1/me', $this->bearer($token), [
            'name' => '수정된이름',
            'contact' => '010-9999-8888',
            'company' => '새회사',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->decode($response)['data'];
        self::assertSame('수정된이름', $data['name']);
        self::assertSame('010-9999-8888', $data['contact']);
        self::assertSame('새회사', $data['company']);
    }

    public function testChangesPasswordWithCorrectCurrentPassword(): void
    {
        $email = $this->seedUser('aivance');
        $token = $this->loginToken($email);
        $newPassword = 'Ch4nged!Passw0rd';

        $response = $this->handle('PATCH', '/api/v1/me', $this->bearer($token), [
            'password' => $newPassword,
            'current_password' => self::PASSWORD,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // 새 비밀번호로 로그인 가능, 기존 비밀번호로는 불가.
        $login = $this->handle('POST', '/api/v1/tokens', [], ['email' => $email, 'password' => $newPassword]);
        self::assertSame(201, $login->getStatusCode(), (string) $login->getBody());

        $oldLogin = $this->handle('POST', '/api/v1/tokens', [], ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(401, $oldLogin->getStatusCode());
    }

    public function testPasswordChangeWithWrongCurrentPasswordIsRejected(): void
    {
        $email = $this->seedUser('aivance');
        $token = $this->loginToken($email);

        $response = $this->handle('PATCH', '/api/v1/me', $this->bearer($token), [
            'password' => 'Ch4nged!Passw0rd',
            'current_password' => 'Wr0ng!Passw0rd',
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('INVALID_CREDENTIALS', $this->decode($response)['code']);
    }

    public function testWithdrawRequiresCorrectPassword(): void
    {
        $email = $this->seedUser('aivance');
        $token = $this->loginToken($email);

        $response = $this->handle('DELETE', '/api/v1/me', $this->bearer($token), ['password' => 'Wr0ng!Passw0rd']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('INVALID_CREDENTIALS', $this->decode($response)['code']);
    }

    public function testWithdrawWithoutPasswordIsValidationError(): void
    {
        $email = $this->seedUser('aivance');
        $token = $this->loginToken($email);

        $response = $this->handle('DELETE', '/api/v1/me', $this->bearer($token), []);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($response)['code']);
    }

    public function testWithdrawSoftDeletesAndBlocksLogin(): void
    {
        $email = $this->seedUser('aivance');
        $token = $this->loginToken($email);

        $response = $this->handle('DELETE', '/api/v1/me', $this->bearer($token), ['password' => self::PASSWORD]);
        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        // 탈퇴 후에는 로그인이 불가능하다.
        $login = $this->handle('POST', '/api/v1/tokens', [], ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(401, $login->getStatusCode());

        // 동일 이메일로 재가입이 가능해야 한다(탈퇴 이메일은 점유 해제).
        $register = $this->handle('POST', '/api/v1/users', [], $this->registerPayload($email, 'aivance'));
        self::assertSame(201, $register->getStatusCode(), (string) $register->getBody());
    }

    public function testRequiresAuthentication(): void
    {
        $response = $this->handle('PATCH', '/api/v1/me', [], ['name' => '홍길동']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', $this->decode($response)['code']);
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────

    /**
     * 이메일 인증 완료·활성 상태의 일반회원을 DB 에 직접 삽입하고 이메일을 반환한다.
     */
    private function seedUser(string $affiliation): string
    {
        $email = $this->uniqueEmail($affiliation);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users
                (email, password_hash, affiliation, role, name, contact,
                 email_verified_at, terms_agreed_at, third_party_agreed_at, is_active, created_at)
             VALUES (?, ?, ?, 3, ?, ?, ?, ?, ?, 1, ?)',
        );
        $stmt->execute([
            $email,
            password_hash(self::PASSWORD, PASSWORD_ARGON2ID),
            $affiliation,
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
        $login = $this->handle('POST', '/api/v1/tokens', [], [
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $login->getStatusCode(), (string) $login->getBody());

        return (string) $this->decode($login)['data']['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function registerPayload(string $email, string $affiliation): array
    {
        return [
            'email' => $email,
            'password' => self::PASSWORD,
            'affiliation' => $affiliation,
            'name' => '재가입',
            'contact' => '010-1111-2222',
            'terms_agreed' => true,
            'third_party_agreed' => true,
        ];
    }

    private function uniqueEmail(string $affiliation): string
    {
        $email = 'me-' . bin2hex(random_bytes(5)) . "@{$affiliation}.test";
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
