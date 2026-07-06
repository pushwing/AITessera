<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Request\LogRequest;
use App\Service\LogService;
use App\Support\Queue\QueueInterface;
use PHPUnit\Framework\TestCase;

final class LogServiceTest extends TestCase
{
    public function testRecordEnqueuesPayload(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')
            ->with(LogService::LOG_QUEUE, self::callback(
                static fn (array $p): bool => $p['level'] === 'error' && $p['message'] === 'boom',
            ));

        (new LogService($queue))->record(
            new LogRequest('error', 'boom', ['k' => 'v'], 'web', 42, null),
        );
    }
}
