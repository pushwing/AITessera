<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Log\DailyLogStats;

/**
 * 개발·테스트용 무동작 리포트 작성기 — AI 호출 없이 항상 NULL 을 반환한다.
 *
 * ANTHROPIC_API_KEY 가 없거나 테스트 환경일 때 바인딩되어, 외부 API 호출 없이도
 * 일일 리포트 파이프라인이 동작하도록 한다. NULL 을 받은 워커는 통계 기반 폴백
 * 리포트를 조립해 발송한다. 운영에서는 ClaudeLogReportWriter 로 교체한다.
 */
final class NullLogReportWriter implements LogReportWriterInterface
{
    public function write(DailyLogStats $target, DailyLogStats $previous): ?string
    {
        return null;
    }
}
