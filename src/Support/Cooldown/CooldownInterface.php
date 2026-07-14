<?php

declare(strict_types=1);

namespace App\Support\Cooldown;

/**
 * 쿨다운(중복 억제) 계약 — 같은 키의 반복 이벤트를 일정 시간 동안 1회로 눌러준다.
 *
 * 로그인 이상 실시간 알림에서 brute-force 공격 시 같은 계정+IP 로 수백 통의 메일이
 * 날아가는 것을 막는 데 쓴다. Redis 구현은 원자적 SET NX EX 로, 테스트 구현은 인메모리로 동작한다.
 */
interface CooldownInterface
{
    /**
     * 주어진 키의 쿨다운을 시작한다.
     *
     * 아직 쿨다운 중이 아니었다면(=이번에 새로 획득) TRUE 를, 이미 쿨다운 중이면 FALSE 를
     * 반환한다(Redis SET NX EX 시맨틱). 호출측은 TRUE 일 때만 알림을 발송한다.
     *
     * @param string $key        중복 억제 단위 키 (예: 계정+IP 해시)
     * @param int    $ttlSeconds 쿨다운 유지 시간(초)
     */
    public function acquire(string $key, int $ttlSeconds): bool;
}
