<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

/**
 * Refresh 토큰 데이터 접근 계약.
 */
interface RefreshTokenRepositoryInterface
{
    public function store(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void;

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array;

    public function revoke(int $id, DateTimeImmutable $at): void;

    /**
     * 재사용 감지 시 해당 사용자의 유효한 모든 토큰을 무효화한다.
     */
    public function revokeAllForUser(int $userId, DateTimeImmutable $at): void;
}
