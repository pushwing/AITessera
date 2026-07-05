<?php

declare(strict_types=1);

namespace App\Support\Queue;

/**
 * 비동기 작업 큐 계약 — 페이로드를 채널에 적재한다.
 */
interface QueueInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function push(string $channel, array $payload): void;

    /**
     * 채널에서 다음 페이로드를 꺼낸다. 비어 있으면 null.
     *
     * @return array<array-key, mixed>|null
     */
    public function pop(string $channel): ?array;
}
