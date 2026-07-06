<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repository\UserRepository;
use App\Support\Config;
use App\Support\Database;
use DateTimeImmutable;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * 실제 DB 를 대상으로 한 UserRepository 통합 테스트.
 *
 * 네이티브 prepared statement(EMULATE_PREPARES=false)에서는 같은 named placeholder 를
 * 재사용하면 SQLSTATE[HY093] 가 발생한다. 목킹 테스트로는 이 결함을 잡을 수 없으므로
 * 여기서 실제 쿼리를 실행해 회귀를 방지한다.
 *
 * DB 가 없으면(로컬 무-DB 등) 스킵한다. CI·로컬 MySQL 에서는 트랜잭션 후 롤백한다.
 */
final class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repository;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        try {
            $database = new Database(Config::fromEnv());
            $this->pdo = $database->pdo();
            $this->pdo->query('SELECT 1');
        } catch (Throwable $e) {
            self::markTestSkipped('DB 미가용 — 통합 테스트 스킵: ' . $e->getMessage());
        }

        $this->repository = new UserRepository($database);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testCreateAndTimestampUpdatesUseDistinctPlaceholders(): void
    {
        // 아래 3개 쿼리는 예전에 같은 placeholder 를 재사용해 HY093 로 실패했다.
        $id = $this->repository->create(
            email: 'itest-' . bin2hex(random_bytes(5)) . '@aivance.test',
            passwordHash: password_hash('irrelevant', PASSWORD_BCRYPT),
            affiliation: 'aivance',
            name: '통합테스트',
            contact: '010-0000-0000',
            company: null,
            profile: [],
            agreedAt: new DateTimeImmutable('2026-07-06 09:00:00'),
        );
        self::assertGreaterThan(0, $id);

        $this->repository->markEmailVerified($id, new DateTimeImmutable('2026-07-06 09:01:00'));
        $this->repository->updateLastLogin($id, new DateTimeImmutable('2026-07-06 09:02:00'));

        $row = $this->repository->findById($id);
        self::assertNotNull($row);
        self::assertSame('aivance', $row['affiliation']);
        self::assertNotNull($row['email_verified_at']);
    }

    public function testFindProfileByIdReturnsSafeColumns(): void
    {
        $id = $this->repository->create(
            email: 'itest-' . bin2hex(random_bytes(5)) . '@aivance.test',
            passwordHash: password_hash('irrelevant', PASSWORD_BCRYPT),
            affiliation: 'aicura',
            name: '프로필테스트',
            contact: '010-1111-2222',
            company: 'AIvance',
            profile: ['age' => 30, 'sex' => 'M', 'where_from' => 'Seoul'],
            agreedAt: new DateTimeImmutable('2026-07-06 09:00:00'),
        );

        $row = $this->repository->findProfileById($id);

        self::assertNotNull($row);
        self::assertArrayNotHasKey('password_hash', $row, 'password_hash 는 절대 노출되면 안 된다');
        self::assertSame('프로필테스트', $row['name']);
        self::assertArrayHasKey('created_at', $row);

        $profile = json_decode((string) $row['profile'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['age' => 30, 'sex' => 'M', 'where_from' => 'Seoul'], $profile);
    }
}
