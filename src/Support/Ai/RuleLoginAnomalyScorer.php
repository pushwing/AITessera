<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Security\AnomalyScore;
use App\Domain\Security\LoginEventSignals;

/**
 * 규칙 기반 로그인 이상 스코어러 — 외부 호출 없이 결정론적으로 점수를 낸다.
 *
 * 하이브리드 구조의 항상 동작하는 기준선이다. AI 미가용(키 없음·테스트·Claude 실패) 시
 * 그대로 쓰이고, Claude 스코어러가 실패했을 때의 폴백으로도 쓰인다. 규칙은 이슈 #52 의
 * 세 가지 신호를 가중합한다: 분산 IP 시도 · 실패 급증 · 실패 급증 후 성공(스터핑 성공 징후).
 */
final class RuleLoginAnomalyScorer implements LoginAnomalyScorerInterface
{
    /** 분산 공격으로 간주하는 최소 서로 다른 IP 수. */
    private const int MULTI_IP_MIN = 3;
    /** 브루트포스로 간주하는 최소 실패 수. */
    private const int FAILURE_MIN = 5;
    /** 스터핑 성공으로 간주하는, 성공 직전 최소 실패 수. */
    private const int STUFFING_FAILURE_MIN = 3;

    public function score(bool $success, LoginEventSignals $signals): AnomalyScore
    {
        $score = 0;
        $reasons = [];

        if ($signals->distinctIpCount >= self::MULTI_IP_MIN) {
            $score += 40;
            $reasons[] = sprintf('동일 계정 %d개 IP 시도', $signals->distinctIpCount);
        }

        if ($signals->failureCount >= self::FAILURE_MIN) {
            $score += 30;
            $reasons[] = sprintf('실패 %d회', $signals->failureCount);
        }

        if ($success && $signals->failureCount >= self::STUFFING_FAILURE_MIN) {
            $score += 50;
            $reasons[] = sprintf('실패 급증(%d회) 후 성공 — 크리덴셜 스터핑 의심', $signals->failureCount);
        }

        $score = min($score, 100);

        return new AnomalyScore($score, $reasons === [] ? '정상' : implode(', ', $reasons));
    }
}
