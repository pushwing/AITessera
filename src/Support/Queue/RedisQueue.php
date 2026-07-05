<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Predis\Client as RedisClient;

/**
 * Redis 리스트 기반 큐 — `lpush` 로 적재하고 `rpop` 으로 소비한다(FIFO).
 */
final readonly class RedisQueue implements QueueInterface
{
    public function __construct(private RedisClient $redis)
    {
    }

    public function push(string $channel, array $payload): void
    {
        $this->redis->lpush($channel, [json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    public function pop(string $channel): ?array
    {
        $raw = $this->redis->rpop($channel);
        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
