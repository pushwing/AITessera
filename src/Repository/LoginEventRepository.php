<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Security\DailySecurityStats;
use App\Domain\Security\LoginEventSignals;
use App\Support\ConnectionInterface;
use DateTimeImmutable;
use PDO;

/**
 * PDO 기반 로그인 이벤트 저장소 — 이상 탐지용 인증 이벤트 저장·집계·스코어 갱신.
 */
final readonly class LoginEventRepository implements LoginEventRepositoryInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function insert(
        string $email,
        string $ip,
        ?string $userAgent,
        bool $success,
        string $occurredAt,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO login_events (email, ip, user_agent, success, occurred_at)
             VALUES (:email, :ip, :user_agent, :success, :occurred_at)',
        );
        $stmt->execute([
            'email' => $email,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'success' => $success ? 1 : 0,
            'occurred_at' => $occurredAt,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function signalsFor(string $email, string $since): LoginEventSignals
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS attempts,
                    COUNT(DISTINCT ip) AS ips,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures
             FROM login_events
             WHERE email = :email AND occurred_at >= :since',
        );
        $stmt->execute(['email' => $email, 'since' => $since]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return new LoginEventSignals(0, 0, 0);
        }

        return new LoginEventSignals(
            distinctIpCount: (int) ($row['ips'] ?? 0),
            failureCount: (int) ($row['failures'] ?? 0),
            attemptCount: (int) ($row['attempts'] ?? 0),
        );
    }

    public function updateScore(int $id, int $score, string $reason): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE login_events SET anomaly_score = :score, anomaly_reason = :reason WHERE id = :id',
        );
        $stmt->execute([
            'score' => $score,
            'reason' => $reason,
            'id' => $id,
        ]);
    }

    public function aggregateForDate(string $date, int $threshold): DailySecurityStats
    {
        $start = $date . ' 00:00:00';
        $end = (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d H:i:s');
        $range = ['start' => $start, 'end' => $end];

        $totals = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS attempts,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures,
                    SUM(CASE WHEN anomaly_score >= :threshold THEN 1 ELSE 0 END) AS anomalies,
                    MAX(anomaly_score) AS max_score
             FROM login_events
             WHERE occurred_at >= :start AND occurred_at < :end',
        );
        $totals->execute($range + ['threshold' => $threshold]);
        $totalsRow = $totals->fetch(PDO::FETCH_ASSOC);
        $totalsRow = is_array($totalsRow) ? $totalsRow : [];

        return new DailySecurityStats(
            date: $date,
            totalAttempts: (int) ($totalsRow['attempts'] ?? 0),
            failedAttempts: (int) ($totalsRow['failures'] ?? 0),
            anomalyCount: (int) ($totalsRow['anomalies'] ?? 0),
            maxScore: (int) ($totalsRow['max_score'] ?? 0),
            topAccounts: $this->topAccounts($range),
            topIps: $this->topIps($range),
        );
    }

    /**
     * 실패 시도 상위 계정 5개 — 실패 수 내림차순.
     *
     * @param array{start: string, end: string} $range
     *
     * @return array<int, array{email: string, failures: int, attempts: int}>
     */
    private function topAccounts(array $range): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT email,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures,
                    COUNT(*) AS attempts
             FROM login_events
             WHERE occurred_at >= :start AND occurred_at < :end
             GROUP BY email
             HAVING failures > 0
             ORDER BY failures DESC, attempts DESC
             LIMIT 5',
        );
        $stmt->execute($range);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'email' => (string) $row['email'],
                'failures' => (int) $row['failures'],
                'attempts' => (int) $row['attempts'],
            ];
        }

        return $out;
    }

    /**
     * 시도 상위 IP 5개 — 시도 수 내림차순.
     *
     * @param array{start: string, end: string} $range
     *
     * @return array<int, array{ip: string, attempts: int}>
     */
    private function topIps(array $range): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ip, COUNT(*) AS attempts
             FROM login_events
             WHERE occurred_at >= :start AND occurred_at < :end
             GROUP BY ip
             ORDER BY attempts DESC
             LIMIT 5',
        );
        $stmt->execute($range);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'ip' => (string) $row['ip'],
                'attempts' => (int) $row['attempts'],
            ];
        }

        return $out;
    }
}
