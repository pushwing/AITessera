<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Log\DailyLogStats;

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
        ?string $aiCategory = null,
        ?string $aiSummary = null,
    ): void;

    /**
     * 특정 날짜(created_at 00:00:00 ~ 다음날 00:00:00 직전)의 로그를 레벨별·소스별로 집계한다.
     *
     * source 가 NULL 인 로그는 'unknown' 으로 집계한다. 인덱스(idx_client_logs_created_at)를
     * 활용하는 범위 조건으로 조회한다.
     */
    public function aggregateByDate(string $date): DailyLogStats;
}
