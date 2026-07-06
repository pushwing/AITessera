<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\ConnectionInterface;
use DateTimeImmutable;
use PDO;

/**
 * PDO 기반 이메일 인증 토큰 저장소.
 */
final readonly class EmailVerificationRepository implements EmailVerificationRepositoryInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function store(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)',
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByHash(string $tokenHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, expires_at, consumed_at FROM email_verifications
             WHERE token_hash = :token_hash
             LIMIT 1',
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function consume(int $id, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE email_verifications SET consumed_at = :at WHERE id = :id AND consumed_at IS NULL',
        );
        $stmt->execute([
            'at' => $at->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function deleteUnconsumedForUser(int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM email_verifications WHERE user_id = :user_id AND consumed_at IS NULL',
        );
        $stmt->execute(['user_id' => $userId]);
    }
}
