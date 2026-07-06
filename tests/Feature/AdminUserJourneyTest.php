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
 * QC E2E — 운영자용 회원 관리 API(이슈 #34): 목록·상세·수정.
 *
 * 실제 미들웨어 파이프라인 + 실 DB 를 관통시켜 소속 스코핑·인가·자기 잠금 방지를 검증한다.
 *   - 목록: 본인 소속만·필터·페이징 meta
 *   - 상세: 회원구분·활성상태 노출 / 타 소속은 404
 *   - 수정: 프로필·role·is_active·비밀번호 변경, 본인 role/is_active 변경 차단(403)
 *   - 인가: 일반회원 토큰 403, 토큰 없음 401
 *
 * DB 가 없으면 스킵한다. 생성 사용자는 tearDown 에서 이메일로 삭제한다.
 */
final class AdminUserJourneyTest extends TestCase
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

    public function testOperatorListsOnlyOwnAffiliationMembers(): void
    {
        $operatorEmail = $this->seedUser('aivance', 1);
        $memberEmail = $this->seedUser('aivance', 3);
        $this->seedUser('aicura', 3); // 타 소속 — 목록에 나오면 안 됨

        $token = $this->loginToken($operatorEmail);
        $response = $this->handle('GET', '/api/v1/users', $this->bearer($token));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->decode($response);

        /** @var list<array<string, mixed>> $items */
        $items = $body['data'];
        $emails = array_column($items, 'email');
        self::assertContains($operatorEmail, $emails);
        self::assertContains($memberEmail, $emails);
        foreach ($items as $item) {
            self::assertSame('aivance', $item['affiliation']); // 타 소속 누출 없음
        }

        // 페이지네이션 meta 표준 4필드 확인
        self::assertArrayHasKey('meta', $body);
        /** @var array<string, mixed> $meta */
        $meta = $body['meta'];
        foreach (['page', 'per_page', 'total', 'last_page'] as $key) {
            self::assertArrayHasKey($key, $meta);
        }
    }

    public function testListRoleFilterNarrowsResults(): void
    {
        $operatorEmail = $this->seedUser('aivance', 1);
        $memberEmail = $this->seedUser('aivance', 3);

        $token = $this->loginToken($operatorEmail);
        $response = $this->handle('GET', '/api/v1/users?role=3', $this->bearer($token));

        self::assertSame(200, $response->getStatusCode());
        /** @var list<array<string, mixed>> $items */
        $items = $this->decode($response)['data'];
        foreach ($items as $item) {
            self::assertSame(3, $item['role']);
        }
        self::assertContains($memberEmail, array_column($items, 'email'));
    }

    public function testOperatorReadsMemberDetailWithRole(): void
    {
        $operatorEmail = $this->seedUser('aivance', 1);
        $memberEmail = $this->seedUser('aivance', 2);
        $memberId = $this->userId($memberEmail);

        $token = $this->loginToken($operatorEmail);
        $response = $this->handle('GET', "/api/v1/users/{$memberId}", $this->bearer($token));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->decode($response)['data'];
        self::assertSame($memberId, $data['id']);
        self::assertSame(2, $data['role']);
        self::assertArrayHasKey('is_active', $data);
    }

    public function testDetailOfOtherAffiliationIsNotFound(): void
    {
        $operatorEmail = $this->seedUser('aivance', 1);
        $otherEmail = $this->seedUser('aicura', 3);
        $otherId = $this->userId($otherEmail);

        $token = $this->loginToken($operatorEmail);
        $response = $this->handle('GET', "/api/v1/users/{$otherId}", $this->bearer($token));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('NOT_FOUND', $this->decode($response)['code']);
    }

    public function testOperatorUpdatesMemberRoleAndActive(): void
    {
        $operatorEmail = $this->seedUser('aivance', 1);
        $memberEmail = $this->seedUser('aivance', 3);
        $memberId = $this->userId($memberEmail);

        $token = $this->loginToken($operatorEmail);
        $response = $this->handle('PATCH', "/api/v1/users/{$memberId}", $this->bearer($token), [
            'role' => 2,
            'is_active' => false,
            'name' => '수정된이름',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->decode($response)['data'];
        self::assertSame(2, $data['role']);
        self::assertFalse($data['is_active']);
        self::assertSame('수정된이름', $data['name']);
    }

    public function testOperatorResetsMemberPassword(): void
    {
        $operatorEmail = $this->seedUser('aivance', 1);
        $memberEmail = $this->seedUser('aivance', 3);
        $memberId = $this->userId($memberEmail);
        $newPassword = 'Reset3d!Passw0rd';

        $token = $this->loginToken($operatorEmail);
        $update = $this->handle('PATCH', "/api/v1/users/{$memberId}", $this->bearer($token), [
            'password' => $newPassword,
        ]);
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());

        // 새 비밀번호로 로그인 가능해야 한다.
        $login = $this->handle('POST', '/api/v1/tokens', [], ['email' => $memberEmail, 'password' => $newPassword]);
        self::assertSame(201, $login->getStatusCode(), (string) $login->getBody());
    }

    public function testOperatorCannotChangeOwnRole(): void
    {
        $operatorEmail = $this->seedUser('aivance', 1);
        $operatorId = $this->userId($operatorEmail);

        $token = $this->loginToken($operatorEmail);
        $response = $this->handle('PATCH', "/api/v1/users/{$operatorId}", $this->bearer($token), ['role' => 3]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('FORBIDDEN', $this->decode($response)['code']);
    }

    public function testOperatorCannotDeactivateSelf(): void
    {
        $operatorEmail = $this->seedUser('aivance', 1);
        $operatorId = $this->userId($operatorEmail);

        $token = $this->loginToken($operatorEmail);
        $response = $this->handle('PATCH', "/api/v1/users/{$operatorId}", $this->bearer($token), ['is_active' => false]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('FORBIDDEN', $this->decode($response)['code']);
    }

    public function testMemberTokenIsForbidden(): void
    {
        $memberEmail = $this->seedUser('aivance', 3);
        $token = $this->loginToken($memberEmail);

        $response = $this->handle('GET', '/api/v1/users', $this->bearer($token));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('FORBIDDEN', $this->decode($response)['code']);
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $response = $this->handle('GET', '/api/v1/users');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', $this->decode($response)['code']);
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────

    /**
     * 이메일 인증 완료·활성 상태의 사용자를 DB 에 직접 삽입하고 이메일을 반환한다.
     */
    private function seedUser(string $affiliation, int $role): string
    {
        $email = $this->uniqueEmail('seed', $affiliation);
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

    private function userId(string $email): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn();
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

    private function uniqueEmail(string $prefix, string $affiliation): string
    {
        $email = "adm-{$prefix}-" . bin2hex(random_bytes(5)) . "@{$affiliation}.test";
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

        // 쿼리스트링을 파싱해 getQueryParams() 에 실어준다(nyholm 은 URI 에서 자동 채우지 않음).
        $queryString = $request->getUri()->getQuery();
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
            $request = $request->withQueryParams($queryParams);
        }

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
