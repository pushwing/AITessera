<?php

declare(strict_types=1);

namespace App\Console;

use App\Repository\LogRepositoryInterface;
use App\Service\LogService;
use App\Support\Queue\QueueInterface;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * 로그 큐 컨슈머 — `log_queue` 를 비우며 각 로그를 처리한다.
 *
 * 1. 원시 로그 → var/logs/raw/raw-YYYY-MM-DD.log 에 append (감사·재처리 보존)
 * 2. 가공 후 client_logs 테이블 INSERT
 * 3. 처리 실패 → var/logs/queue-failed/failed-YYYY-MM-DD.log (dead-letter)
 *
 * `bin/console log:work` 로 실행하며 cron / systemd timer 로 주기 기동한다.
 */
final readonly class ProcessLogQueue
{
    private string $rawDir;
    private string $deadLetterDir;

    public function __construct(
        private QueueInterface $queue,
        private LogRepositoryInterface $logs,
        ?string $rawDir = null,
        ?string $deadLetterDir = null,
    ) {
        $root = dirname(__DIR__, 2);
        $this->rawDir = $rawDir ?? $root . '/var/logs/raw';
        $this->deadLetterDir = $deadLetterDir ?? $root . '/var/logs/queue-failed';
    }

    /**
     * 큐가 빌 때까지 처리하고 처리 건수를 반환한다.
     */
    public function run(): int
    {
        $processed = 0;
        while (($job = $this->queue->pop(LogService::LOG_QUEUE)) !== null) {
            $this->handle($job);
            ++$processed;
        }

        return $processed;
    }

    /**
     * @param array<array-key, mixed> $job
     */
    public function handle(array $job): void
    {
        try {
            $this->writeRaw($job);
            $this->store($job);
        } catch (Throwable $e) {
            $this->deadLetter($job, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $job
     */
    private function writeRaw(array $job): void
    {
        $line = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->append($this->rawDir, 'raw-' . date('Y-m-d') . '.log', $line);
    }

    /**
     * @param array<array-key, mixed> $job
     */
    private function store(array $job): void
    {
        $level = $job['level'] ?? null;
        $message = $job['message'] ?? null;
        if (!is_string($level) || !is_string($message) || $message === '') {
            throw new RuntimeException('로그 형식이 올바르지 않습니다.');
        }

        $context = $job['context'] ?? [];
        $sourceRaw = $job['source'] ?? null;
        $userIdRaw = $job['user_id'] ?? null;

        $this->logs->insert(
            level: $level,
            message: $message,
            context: is_array($context) ? $context : [],
            source: is_string($sourceRaw) && $sourceRaw !== '' ? $sourceRaw : null,
            userId: is_int($userIdRaw) ? $userIdRaw : null,
            loggedAt: $this->normalizeDate($job['logged_at'] ?? null),
        );
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $job
     */
    private function deadLetter(array $job, Throwable $e): void
    {
        $line = json_encode(
            ['error' => $e->getMessage(), 'job' => $job],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->append($this->deadLetterDir, 'failed-' . date('Y-m-d') . '.log', $line === false ? '{}' : $line);
    }

    private function append(string $dir, string $file, string $line): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        file_put_contents($dir . '/' . $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
