<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Security\AnomalyScore;
use App\Domain\Security\LoginEventSignals;

/**
 * 로그인 이상 스코어링 계약(하이브리드).
 *
 * 최근 윈도우의 집계 신호(LoginEventSignals)와 이번 시도의 성공 여부를 받아 이상 점수를
 * 산출한다. 규칙 기반 구현(RuleLoginAnomalyScorer)은 항상 동작하고, Claude 구현
 * (ClaudeLoginAnomalyScorer)은 규칙 신호를 근거로 종합 점수를 정교화하되 외부 호출 실패
 * 시 규칙 기반으로 폴백한다(graceful degradation). 바인딩은 컨테이너에서 환경별로 정한다.
 */
interface LoginAnomalyScorerInterface
{
    public function score(bool $success, LoginEventSignals $signals): AnomalyScore;
}
