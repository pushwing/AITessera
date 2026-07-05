<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\ConnectionInterface;
use DateTimeImmutable;
use PDO;

/**
 * PDO 기반 사용자 저장소 — 모든 쿼리는 prepared statement + 바인딩.
 */
final readonly class UserRepository implements UserRepositoryInterface
{
    private const string COLUMNS = 'id, email, password_hash, affiliation, is_active, email_verified_at';

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function findActiveByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM users
             WHERE email = :email AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1',
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM users
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1',
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function updateLastLogin(int $id, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET last_login_at = :at, updated_at = :at WHERE id = :id',
        );
        $stmt->execute([
            'at' => $at->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }
}
