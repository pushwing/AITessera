<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Log\DailyLogStats;

/**
 * 일일 로그 리포트 작성 계약.
 *
 * 하루치 집계 통계(대상일 + 전일)를 받아 자연어 운영 리포트를 생성한다. 원시 로그가 아닌
 * 집계 결과만 전달해 토큰을 절약한다(부하·비용 통제). 부트스트랩·테스트 단계에서는
 * 무동작 구현(NullLogReportWriter)을 바인딩하고, 운영에서는 Claude API 구현
 * (ClaudeLogReportWriter)으로 교체한다(컨테이너 바인딩만 변경).
 */
interface LogReportWriterInterface
{
    /**
     * 집계 통계를 바탕으로 자연어 리포트 본문을 생성한다.
     *
     * 외부 호출 실패·파싱 오류 시 NULL 을 반환한다 — 호출측이 통계 기반 폴백으로 처리한다
     * (graceful degradation, 리포트 발송이 AI 장애로 중단되지 않도록).
     *
     * @param DailyLogStats $target   리포트 대상일 집계
     * @param DailyLogStats $previous 전일 집계 (증감 비교용)
     */
    public function write(DailyLogStats $target, DailyLogStats $previous): ?string;
}
