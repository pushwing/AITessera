<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\ProcessLogQueue;
use App\Domain\Ai\LogAiResult;
use App\Repository\LogRepositoryInterface;
use App\Support\Ai\LogAiClassifierInterface;
use App\Support\Ai\NullLogAiClassifier;
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
            ->with('error', 'boom', ['code' => 500], 'web', 42, '2026-07-05 10:00:00', null, null);

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

        $worker = new ProcessLogQueue($queue, $repo, new NullLogAiClassifier(), $this->rawDir, $this->deadDir);

        self::assertSame(2, $worker->run());
    }

    public function testRunClassifiesErrorLogsAndStoresAiColumns(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['level' => 'error', 'message' => 'DB timeout'],
            null,
        );

        // error 로그 1건 → AI 분류기가 카테고리·요약을 반환
        $classifier = $this->createMock(LogAiClassifierInterface::class);
        $classifier->expects(self::once())->method('classify')
            ->willReturn([0 => new LogAiResult('database', 'DB 연결 타임아웃')]);

        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->expects(self::once())->method('insert')
            ->with('error', 'DB timeout', [], null, null, null, 'database', 'DB 연결 타임아웃');

        $worker = new ProcessLogQueue($queue, $repo, $classifier, $this->rawDir, $this->deadDir);

        self::assertSame(1, $worker->run());
    }

    public function testRunSkipsAiForNonErrorLevels(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['level' => 'info', 'message' => 'ping'],
            null,
        );

        // info 로그는 AI 분류 대상이 아니므로 classify 가 호출되면 안 된다
        $classifier = $this->createMock(LogAiClassifierInterface::class);
        $classifier->expects(self::never())->method('classify');

        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->expects(self::once())->method('insert')
            ->with('info', 'ping', [], null, null, null, null, null);

        $worker = new ProcessLogQueue($queue, $repo, $classifier, $this->rawDir, $this->deadDir);

        self::assertSame(1, $worker->run());
    }

    public function testAiFailureStillStoresLogWithNullColumns(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['level' => 'critical', 'message' => 'kernel panic'],
            null,
        );

        // AI 호출이 예외를 던져도 로그는 정상 저장되어야 한다(파이프라인 무중단)
        $classifier = $this->createMock(LogAiClassifierInterface::class);
        $classifier->method('classify')->willThrowException(new \RuntimeException('API down'));

        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->expects(self::once())->method('insert')
            ->with('critical', 'kernel panic', [], null, null, null, null, null);

        $worker = new ProcessLogQueue($queue, $repo, $classifier, $this->rawDir, $this->deadDir);

        self::assertSame(1, $worker->run());
        self::assertFalse(is_dir($this->deadDir), 'AI 실패는 dead-letter 를 만들지 않아야 한다');
    }

    private function worker(LogRepositoryInterface $repo): ProcessLogQueue
    {
        return new ProcessLogQueue(
            $this->createMock(QueueInterface::class),
            $repo,
            new NullLogAiClassifier(),
            $this->rawDir,
            $this->deadDir,
        );
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
