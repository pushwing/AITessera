<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * 로그인 이상 스코어링 결과 — 0~100 점수와 판단 근거(한 줄).
 *
 * `login_events.anomaly_score` · `anomaly_reason` 컬럼에 그대로 저장된다.
 */
final readonly class AnomalyScore
{
    public function __construct(
        /** 이상 정도 점수(0=정상 ~ 100=강한 이상). */
        public int $score,
        /** 점수 산출 근거(한국어 한 줄). */
        public string $reason,
    ) {
    }
}
