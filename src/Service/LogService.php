<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Request\LogRequest;
use App\Support\Queue\QueueInterface;

/**
 * 로그 수집 — 수신 즉시 큐에 적재한다(DB 직접 쓰기 금지).
 *
 * 실제 파일 저장·DB INSERT 는 큐 컨슈머(ProcessLogQueue)가 비동기로 처리한다.
 */
final readonly class LogService
{
    public const string LOG_QUEUE = 'log_queue';

    public function __construct(private QueueInterface $queue)
    {
    }

    public function record(LogRequest $request): void
    {
        $this->queue->push(self::LOG_QUEUE, $request->toPayload());
    }
}
