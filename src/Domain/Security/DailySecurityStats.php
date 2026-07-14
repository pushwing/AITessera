<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * 하루치 로그인 이벤트 보안 집계 — 일일 보안 리포트의 입력.
 *
 * login_events 를 대상일 범위로 집계한 수치만 담는다. 원시 이벤트가 아닌 집계 결과만
 * AI 작성기에 전달해 토큰을 아끼고(부하·비용 통제), AI 미가용 시 통계 폴백 조립에 쓴다.
 */
final readonly class DailySecurityStats
{
    /**
     * @param string                                                     $date          집계 대상 날짜 (Y-m-d)
     * @param int                                                        $totalAttempts 전체 로그인 시도 수
     * @param int                                                        $failedAttempts 실패 시도 수
     * @param int                                                        $anomalyCount  임계값 이상(이상) 이벤트 수
     * @param int                                                        $maxScore      당일 최고 이상 점수(0~100)
     * @param array<int, array{email: string, failures: int, attempts: int}> $topAccounts 실패 상위 계정
     * @param array<int, array{ip: string, attempts: int}>              $topIps        시도 상위 IP
     */
    public function __construct(
        public string $date,
        public int $totalAttempts,
        public int $failedAttempts,
        public int $anomalyCount,
        public int $maxScore,
        public array $topAccounts,
        public array $topIps,
    ) {
    }
}
