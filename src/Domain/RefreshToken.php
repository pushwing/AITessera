<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;
use DateTimeImmutable;

/**
 * Refresh 토큰 도메인 모델 — DB 행을 타입 안전하게 매핑한다.
 */
final readonly class RefreshToken
{
    public function __construct(
        public int $id,
        public int $userId,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
            revokedAt: Dates::nullable($row['revoked_at'] ?? null),
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
