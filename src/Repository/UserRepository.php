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

    public function emailExists(string $email): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(
        string $email,
        string $passwordHash,
        string $affiliation,
        string $name,
        string $contact,
        ?string $company,
        array $profile,
        DateTimeImmutable $agreedAt,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, affiliation, name, contact, company, profile,
                 terms_agreed_at, third_party_agreed_at, created_at)
             VALUES
                (:email, :password_hash, :affiliation, :name, :contact, :company, :profile,
                 :terms_agreed_at, :third_party_agreed_at, :created_at)',
        );
        $timestamp = $agreedAt->format('Y-m-d H:i:s');
        $stmt->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'affiliation' => $affiliation,
            'name' => $name,
            'contact' => $contact,
            'company' => $company,
            'profile' => $profile === [] ? null : json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'terms_agreed_at' => $timestamp,
            'third_party_agreed_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function markEmailVerified(int $id, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET email_verified_at = :at, updated_at = :updated_at WHERE id = :id',
        );
        $timestamp = $at->format('Y-m-d H:i:s');
        $stmt->execute([
            'at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => $id,
        ]);
    }

    public function updateLastLogin(int $id, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET last_login_at = :at, updated_at = :updated_at WHERE id = :id',
        );
        $timestamp = $at->format('Y-m-d H:i:s');
        $stmt->execute([
            'at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => $id,
        ]);
    }
}
