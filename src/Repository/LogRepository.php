<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Log\DailyLogStats;
use App\Support\ConnectionInterface;
use DateTimeImmutable;
use PDO;

/**
 * PDO 기반 클라이언트 로그 저장소.
 */
final readonly class LogRepository implements LogRepositoryInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function insert(
        string $level,
        string $message,
        array $context,
        ?string $source,
        ?int $userId,
        ?string $loggedAt,
        ?string $aiCategory = null,
        ?string $aiSummary = null,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO client_logs (level, message, context, source, user_id, logged_at, ai_category, ai_summary)
             VALUES (:level, :message, :context, :source, :user_id, :logged_at, :ai_category, :ai_summary)',
        );
        $stmt->execute([
            'level' => $level,
            'message' => $message,
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'source' => $source,
            'user_id' => $userId,
            'logged_at' => $loggedAt,
            'ai_category' => $aiCategory,
            'ai_summary' => $aiSummary,
        ]);
    }

    public function aggregateByDate(string $date): DailyLogStats
    {
        $start = $date . ' 00:00:00';
        $end = (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d H:i:s');

        $byLevel = $this->countGrouped(
            'SELECT level AS grp, COUNT(*) AS cnt FROM client_logs
             WHERE created_at >= :start AND created_at < :end
             GROUP BY level',
            $start,
            $end,
        );
        $bySource = $this->countGrouped(
            "SELECT COALESCE(source, 'unknown') AS grp, COUNT(*) AS cnt FROM client_logs
             WHERE created_at >= :start AND created_at < :end
             GROUP BY COALESCE(source, 'unknown')",
            $start,
            $end,
        );

        return new DailyLogStats($date, array_sum($byLevel), $byLevel, $bySource);
    }

    /**
     * grp(문자열) → cnt(정수) 형태로 GROUP BY 집계 결과를 조회한다.
     *
     * @return array<string, int>
     */
    private function countGrouped(string $sql, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $grp = $row['grp'] ?? null;
            $cnt = $row['cnt'] ?? null;
            if (!is_string($grp) || !is_numeric($cnt)) {
                continue;
            }
            $out[$grp] = (int) $cnt;
        }

        return $out;
    }
}
