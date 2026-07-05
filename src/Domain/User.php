<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;
use DateTimeImmutable;

/**
 * 사용자 도메인 모델 — Repository 가 반환한 DB 행(배열)을 타입 안전하게 매핑한다.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $passwordHash,
        public Affiliation $affiliation,
        public bool $isActive,
        public ?DateTimeImmutable $emailVerifiedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            affiliation: Affiliation::from((string) $row['affiliation']),
            isActive: (bool) $row['is_active'],
            emailVerifiedAt: Dates::nullable($row['email_verified_at'] ?? null),
        );
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }
}
