<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * 클라이언트 로그 데이터 접근 계약.
 */
interface LogRepositoryInterface
{
    /**
     * @param array<array-key, mixed> $context
     */
    public function insert(
        string $level,
        string $message,
        array $context,
        ?string $source,
        ?int $userId,
        ?string $loggedAt,
    ): void;
}
