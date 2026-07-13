<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Security\DailySecurityStats;

/**
 * 일일 보안 리포트 작성 계약.
 *
 * 하루치 로그인 이벤트 집계(DailySecurityStats)를 받아 자연어 보안 리포트를 생성한다.
 * 원시 이벤트가 아닌 집계 결과만 전달해 토큰을 절약한다. 부트스트랩·테스트에서는 무동작
 * 구현(NullSecurityReportWriter)을, 운영에서는 Claude API 구현(ClaudeSecurityReportWriter)을
 * 바인딩한다(컨테이너 바인딩만 변경).
 */
interface SecurityReportWriterInterface
{
    /**
     * 집계를 바탕으로 자연어 보안 리포트 본문을 생성한다.
     *
     * 외부 호출 실패·파싱 오류 시 NULL 을 반환한다 — 호출측이 통계 기반 폴백으로 처리한다
     * (graceful degradation, 리포트 발송이 AI 장애로 중단되지 않도록).
     */
    public function write(DailySecurityStats $stats): ?string;
}
