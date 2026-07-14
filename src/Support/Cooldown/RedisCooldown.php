<?php

declare(strict_types=1);

namespace App\Support\Cooldown;

use Predis\Client as RedisClient;

/**
 * Redis 기반 쿨다운 — `SET key 1 NX EX ttl` 로 원자적으로 획득 여부를 판정한다.
 *
 * NX(키가 없을 때만 설정) 덕분에 동시 실행 워커에서도 경합 없이 "최초 1회"가 보장된다.
 * 키에는 접두어를 붙여 다른 Redis 데이터와 네임스페이스를 분리한다.
 */
final readonly class RedisCooldown implements CooldownInterface
{
    private const string PREFIX = 'cooldown:';

    public function __construct(private RedisClient $redis)
    {
    }

    public function acquire(string $key, int $ttlSeconds): bool
    {
        // SET ... NX 는 키가 이미 있으면 NULL, 새로 설정하면 Status('OK') 를 반환한다.
        $result = $this->redis->set(self::PREFIX . $key, '1', 'EX', $ttlSeconds, 'NX');

        return $result !== null;
    }
}
