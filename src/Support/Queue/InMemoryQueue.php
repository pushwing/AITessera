<?php

declare(strict_types=1);

namespace App\Support\Queue;

/**
 * 프로세스 메모리 큐 — 테스트·단일 프로세스용(외부 Redis 불필요).
 *
 * 채널별 FIFO 로 동작하며, 프로세스 종료 시 사라진다.
 */
final class InMemoryQueue implements QueueInterface
{
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $channels = [];

    public function push(string $channel, array $payload): void
    {
        $this->channels[$channel][] = $payload;
    }

    public function pop(string $channel): ?array
    {
        if (empty($this->channels[$channel])) {
            return null;
        }

        return array_shift($this->channels[$channel]);
    }
}
