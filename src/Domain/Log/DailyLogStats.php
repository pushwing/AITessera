<?php

declare(strict_types=1);

namespace App\Domain\Log;

/**
 * 하루치 client_logs 집계 결과 — 레벨별·소스별 건수와 총합.
 *
 * 일일 로그 리포트에서 특정 날짜의 통계를 담아 AI 리포트 작성기에 전달하거나,
 * AI 미가용 시 통계 기반 폴백 리포트를 조립하는 데 쓰인다.
 */
final readonly class DailyLogStats
{
    /**
     * @param string             $date     집계 대상 날짜 (Y-m-d)
     * @param int                $total    전체 로그 건수
     * @param array<string, int> $byLevel  레벨 → 건수
     * @param array<string, int> $bySource 소스 → 건수 (source NULL 은 'unknown')
     */
    public function __construct(
        public string $date,
        public int $total,
        public array $byLevel,
        public array $bySource,
    ) {
    }
}
