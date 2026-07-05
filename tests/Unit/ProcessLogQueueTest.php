<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\ProcessLogQueue;
use App\Repository\LogRepositoryInterface;
use App\Support\Queue\QueueInterface;
use PHPUnit\Framework\TestCase;

final class ProcessLogQueueTest extends TestCase
{
    private string $rawDir;
    private string $deadDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/aitessera-log-' . uniqid('', true);
        $this->rawDir = $base . '/raw';
        $this->deadDir = $base . '/dead';
    }

    protected function tearDown(): void
    {
        foreach ([$this->rawDir, $this->deadDir] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function testHandleWritesRawFileAndInsertsProcessed(): void
    {
        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->expects(self::once())->method('insert')
            ->with('error', 'boom', ['code' => 500], 'web', 42, '2026-07-05 10:00:00');

        $this->worker($repo)->handle([
            'level' => 'error',
            'message' => 'boom',
            'context' => ['code' => 500],
            'source' => 'web',
            'user_id' => 42,
            'logged_at' => '2026-07-05 10:00:00',
        ]);

        $raw = $this->readDir($this->rawDir);
        self::assertStringContainsString('boom', $raw);
        self::assertFalse(is_dir($this->deadDir), 'dead-letter 는 생성되지 않아야 한다');
    }

    public function testMalformedPayloadGoesToDeadLetterWithoutInsert(): void
    {
        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->expects(self::never())->method('insert');

        // message 누락 → 가공 실패 → dead-letter
        $this->worker($repo)->handle(['level' => 'error']);

        self::assertStringContainsString('error', $this->readDir($this->deadDir));
    }

    public function testRunDrainsQueue(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['level' => 'info', 'message' => 'a'],
            ['level' => 'info', 'message' => 'b'],
            null,
        );
        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('insert');

        $worker = new ProcessLogQueue($queue, $repo, $this->rawDir, $this->deadDir);

        self::assertSame(2, $worker->run());
    }

    private function worker(LogRepositoryInterface $repo): ProcessLogQueue
    {
        return new ProcessLogQueue($this->createMock(QueueInterface::class), $repo, $this->rawDir, $this->deadDir);
    }

    private function readDir(string $dir): string
    {
        $out = '';
        foreach (glob($dir . '/*') ?: [] as $file) {
            $out .= (string) file_get_contents($file);
        }

        return $out;
    }
}
