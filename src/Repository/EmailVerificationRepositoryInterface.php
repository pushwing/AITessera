<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

/**
 * 이메일 인증 토큰 데이터 접근 계약.
 */
interface EmailVerificationRepositoryInterface
{
    public function store(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void;

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array;

    public function consume(int $id, DateTimeImmutable $at): void;

    /**
     * 아직 사용되지 않은 해당 사용자의 인증 토큰을 모두 삭제한다(재발송 시 이전 토큰 무효화).
     */
    public function deleteUnconsumedForUser(int $userId): void;
}
