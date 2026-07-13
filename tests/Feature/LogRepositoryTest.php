<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repository\LogRepository;
use App\Support\Config;
use App\Support\Database;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * 실제 DB 를 대상으로 한 LogRepository::aggregateByDate 통합 테스트.
 *
 * 집계는 created_at 범위(하루) 기준이므로, 여기서는 created_at 을 명시 지정한 행을 직접
 * INSERT 해 날짜별 레벨·소스 집계가 정확한지 검증한다. 목킹으로는 GROUP BY·범위조건의
 * 회귀를 잡을 수 없어 실 쿼리를 실행한다.
 *
 * DB 가 없으면(로컬 무-DB 등) 스킵한다. CI·로컬 MySQL 에서는 트랜잭션 후 롤백한다.
 */
final class LogRepositoryTest extends TestCase
{
    private PDO $pdo;
    private LogRepository $repository;

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

        $this->repository = new LogRepository($database);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testAggregateByDateCountsByLevelAndSourceWithinDay(): void
    {
        // 대상일(2026-06-01): error×2(web·checkout), info×1(web), source NULL×1
        $this->seed('2026-06-01 09:00:00', 'error', 'web');
        $this->seed('2026-06-01 10:00:00', 'error', 'checkout');
        $this->seed('2026-06-01 11:00:00', 'info', 'web');
        $this->seed('2026-06-01 23:59:59', 'warning', null);
        // 경계 밖(다음날 00:00:00) — 포함되면 안 된다
        $this->seed('2026-06-02 00:00:00', 'error', 'web');
        // 전날 — 포함되면 안 된다
        $this->seed('2026-05-31 12:00:00', 'critical', 'web');

        $stats = $this->repository->aggregateByDate('2026-06-01');

        self::assertSame('2026-06-01', $stats->date);
        self::assertSame(4, $stats->total);
        self::assertSame(['error' => 2, 'info' => 1, 'warning' => 1], $this->sortKeys($stats->byLevel));
        self::assertSame(['checkout' => 1, 'unknown' => 1, 'web' => 2], $this->sortKeys($stats->bySource));
    }

    public function testAggregateByDateReturnsZeroStatsForEmptyDay(): void
    {
        $stats = $this->repository->aggregateByDate('2000-01-01');

        self::assertSame(0, $stats->total);
        self::assertSame([], $stats->byLevel);
        self::assertSame([], $stats->bySource);
    }

    private function seed(string $createdAt, string $level, ?string $source): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO client_logs (level, message, source, created_at)
             VALUES (:level, :message, :source, :created_at)',
        );
        $stmt->execute([
            'level' => $level,
            'message' => 'seed',
            'source' => $source,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * @param array<string, int> $map
     *
     * @return array<string, int>
     */
    private function sortKeys(array $map): array
    {
        ksort($map);

        return $map;
    }
}
