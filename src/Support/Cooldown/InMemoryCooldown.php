<?php

declare(strict_types=1);

namespace App\Support\Cooldown;

/**
 * 인메모리 쿨다운 — 테스트·무-Redis 환경용. 프로세스 수명 동안만 키를 기억한다.
 *
 * TTL 만료는 구현하지 않는다(단일 실행 안에서 "최초 1회 vs 이후 억제"만 검증하면 충분).
 * 운영에서는 RedisCooldown 으로 교체한다(컨테이너 바인딩만 변경).
 */
final class InMemoryCooldown implements CooldownInterface
{
    /** @var array<string, true> 이미 획득한 키 집합. */
    private array $held = [];

    public function acquire(string $key, int $ttlSeconds): bool
    {
        if (isset($this->held[$key])) {
            return false;
        }

        $this->held[$key] = true;

        return true;
    }
}
