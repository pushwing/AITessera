<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * 로그인 이상 탐지용 집계 신호 — 최근 윈도우 안에서 특정 계정에 대한 시도 통계.
 *
 * 이상 스코어링의 규칙 기반 입력이 된다. 저장소(LoginEventRepository)가 시간 윈도우
 * 조건으로 집계해 채운다.
 */
final readonly class LoginEventSignals
{
    public function __construct(
        /** 윈도우 내 해당 계정에 시도한 서로 다른 IP 수 (분산 공격 신호). */
        public int $distinctIpCount,
        /** 윈도우 내 해당 계정의 실패 시도 수 (브루트포스·스터핑 신호). */
        public int $failureCount,
        /** 윈도우 내 해당 계정의 전체 시도 수. */
        public int $attemptCount,
    ) {
    }
}
