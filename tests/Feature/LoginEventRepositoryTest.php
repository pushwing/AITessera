<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repository\LoginEventRepository;
use App\Support\Config;
use App\Support\Database;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * 실제 DB 를 대상으로 한 LoginEventRepository 통합 테스트.
 *
 * signalsFor 의 윈도우 범위 조건·DISTINCT/집계는 목킹으로 회귀를 잡을 수 없어 실 쿼리를
 * 실행한다. DB 가 없으면(로컬 무-DB 등) 스킵하고, 있으면 트랜잭션 후 롤백해 격리한다.
 */
final class LoginEventRepositoryTest extends TestCase
{
    private PDO $pdo;
    private LoginEventRepository $repository;

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

        $this->repository = new LoginEventRepository($database);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testInsertReturnsIdAndUpdateScorePersists(): void
    {
        $id = $this->repository->insert('victim@x.test', '10.0.0.1', 'UA/1.0', false, '2026-07-13 09:00:00');
        self::assertGreaterThan(0, $id);

        $this->repository->updateScore($id, 88, '스터핑 의심');

        $row = $this->fetch($id);
        self::assertSame('victim@x.test', $row['email']);
        self::assertSame(0, (int) $row['success']);
        self::assertSame(88, (int) $row['anomaly_score']);
        self::assertSame('스터핑 의심', $row['anomaly_reason']);
    }

    public function testSignalsForAggregatesDistinctIpAndFailuresWithinWindow(): void
    {
        // 윈도우 내(>= since): 같은 계정에 3개 IP, 실패 2·성공 1 = 시도 3
        $this->repository->insert('target@x.test', '10.0.0.1', null, false, '2026-07-13 09:10:00');
        $this->repository->insert('target@x.test', '10.0.0.2', null, false, '2026-07-13 09:11:00');
        $this->repository->insert('target@x.test', '10.0.0.3', null, true, '2026-07-13 09:12:00');
        // 윈도우 밖(since 이전) — 집계에서 제외되어야 한다
        $this->repository->insert('target@x.test', '10.0.0.9', null, false, '2026-07-13 08:00:00');
        // 다른 계정 — 제외되어야 한다
        $this->repository->insert('other@x.test', '10.0.0.4', null, false, '2026-07-13 09:12:00');

        $signals = $this->repository->signalsFor('target@x.test', '2026-07-13 09:05:00');

        self::assertSame(3, $signals->attemptCount);
        self::assertSame(3, $signals->distinctIpCount);
        self::assertSame(2, $signals->failureCount);
    }

    public function testSignalsForReturnsZeroWhenNoEvents(): void
    {
        $signals = $this->repository->signalsFor('nobody@x.test', '2026-07-13 09:05:00');

        self::assertSame(0, $signals->attemptCount);
        self::assertSame(0, $signals->distinctIpCount);
        self::assertSame(0, $signals->failureCount);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM login_events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
