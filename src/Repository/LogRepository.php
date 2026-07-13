<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\ConnectionInterface;

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
}
