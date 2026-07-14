<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Security\DailySecurityStats;
use App\Domain\Security\LoginEventSignals;

/**
 * 로그인 이벤트 데이터 접근 계약 — 이상 탐지용 인증 이벤트 저장·집계·스코어 갱신.
 */
interface LoginEventRepositoryInterface
{
    /**
     * 로그인 이벤트 1건을 저장하고 생성된 id 를 반환한다.
     *
     * 비밀번호·토큰 등 민감정보는 절대 저장하지 않는다(email·ip·user_agent·성공여부만).
     */
    public function insert(
        string $email,
        string $ip,
        ?string $userAgent,
        bool $success,
        string $occurredAt,
    ): int;

    /**
     * 특정 계정의 최근 윈도우(occurred_at >= :since) 시도를 집계해 이상 탐지 신호를 만든다.
     *
     * (email, occurred_at) 인덱스를 활용하는 범위 조건으로 조회한다.
     */
    public function signalsFor(string $email, string $since): LoginEventSignals;

    /**
     * 스코어링 결과(점수·근거)를 해당 이벤트 행에 기록한다.
     */
    public function updateScore(int $id, int $score, string $reason): void;

    /**
     * 하루치(대상일 00:00:00 이상 ~ 다음날 00:00:00 미만) 로그인 이벤트를 보안 관점으로 집계한다.
     *
     * 일일 보안 리포트의 입력을 만든다. 총 시도·실패·이상(임계값 이상) 건수·최고 점수와
     * 실패 상위 계정·시도 상위 IP 를 산출한다. occurred_at 범위 인덱스를 활용한다.
     *
     * @param string $date      집계 대상 날짜 (Y-m-d)
     * @param int    $threshold 이상으로 집계할 점수 임계값(0~100)
     */
    public function aggregateForDate(string $date, int $threshold): DailySecurityStats;
}
