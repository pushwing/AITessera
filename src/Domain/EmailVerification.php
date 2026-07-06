<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;
use DateTimeImmutable;

/**
 * 이메일 인증 토큰 도메인 모델 — DB 행을 타입 안전하게 매핑한다.
 */
final readonly class EmailVerification
{
    public function __construct(
        public int $id,
        public int $userId,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $consumedAt,
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
            consumedAt: Dates::nullable($row['consumed_at'] ?? null),
        );
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
