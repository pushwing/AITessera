<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\ConnectionInterface;
use DateTimeImmutable;
use PDO;

/**
 * PDO 기반 Refresh 토큰 저장소.
 */
final readonly class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function store(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
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
            'SELECT id, user_id, expires_at, revoked_at FROM refresh_tokens
             WHERE token_hash = :token_hash
             LIMIT 1',
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function revoke(int $id, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE refresh_tokens SET revoked_at = :at WHERE id = :id AND revoked_at IS NULL',
        );
        $stmt->execute([
            'at' => $at->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function revokeAllForUser(int $userId, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE refresh_tokens SET revoked_at = :at WHERE user_id = :user_id AND revoked_at IS NULL',
        );
        $stmt->execute([
            'at' => $at->format('Y-m-d H:i:s'),
            'user_id' => $userId,
        ]);
    }
}
