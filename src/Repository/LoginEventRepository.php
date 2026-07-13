<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Security\LoginEventSignals;
use App\Support\ConnectionInterface;
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
}
