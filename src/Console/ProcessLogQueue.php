<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Ai\LogAiInput;
use App\Domain\Ai\LogAiResult;
use App\Repository\LogRepositoryInterface;
use App\Service\LogService;
use App\Support\Ai\LogAiClassifierInterface;
use App\Support\Queue\QueueInterface;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * 로그 큐 컨슈머 — `log_queue` 를 비우며 각 로그를 처리한다.
 *
 * 1. 원시 로그 → var/logs/raw/raw-YYYY-MM-DD.log 에 append (감사·재처리 보존)
 * 2. error/critical 로그는 배치로 AI 분류·요약 (카테고리·한 줄 요약)
 * 3. 가공 후 client_logs 테이블 INSERT
 * 4. 처리 실패 → var/logs/queue-failed/failed-YYYY-MM-DD.log (dead-letter)
 *
 * `bin/console log:work` 로 실행하며 cron / systemd timer 로 주기 기동한다.
 */
final readonly class ProcessLogQueue
{
    /** AI 분류 대상 로그 레벨 (비용·부하 통제를 위해 심각 로그만 호출). */
    private const array AI_LEVELS = ['error', 'critical'];

    private string $rawDir;
    private string $deadLetterDir;

    public function __construct(
        private QueueInterface $queue,
        private LogRepositoryInterface $logs,
        private LogAiClassifierInterface $classifier,
        ?string $rawDir = null,
        ?string $deadLetterDir = null,
    ) {
        $root = dirname(__DIR__, 2);
        $this->rawDir = $rawDir ?? $root . '/var/logs/raw';
        $this->deadLetterDir = $deadLetterDir ?? $root . '/var/logs/queue-failed';
    }

    /**
     * 큐가 빌 때까지 처리하고 처리 건수를 반환한다.
     *
     * AI 분류는 건별이 아니라 드레인한 전체에서 error/critical 만 모아 한 번에 호출한다
     * (건별 호출 금지 · 비용/부하 통제).
     */
    public function run(): int
    {
        $jobs = [];
        while (($job = $this->queue->pop(LogService::LOG_QUEUE)) !== null) {
            $jobs[] = $job;
        }
        if ($jobs === []) {
            return 0;
        }

        $aiResults = $this->classify($jobs);

        foreach ($jobs as $i => $job) {
            $this->handle($job, $aiResults[$i] ?? null);
        }

        return count($jobs);
    }

    /**
     * error/critical 로그만 골라 한 번의 배치 호출로 AI 분류한다.
     *
     * 외부 API 실패는 여기서 흡수해 빈 결과를 반환한다 — 로그 저장은 계속되어야 하므로
     * AI 실패가 파이프라인을 중단시키지 않는다(graceful degradation).
     *
     * @param list<array<array-key, mixed>> $jobs
     *
     * @return array<int, LogAiResult> 원본 인덱스 → 분류 결과
     */
    private function classify(array $jobs): array
    {
        $inputs = [];
        foreach ($jobs as $i => $job) {
            $level = $job['level'] ?? null;
            $message = $job['message'] ?? null;
            if (is_string($level) && in_array($level, self::AI_LEVELS, true)
                && is_string($message) && $message !== '') {
                $inputs[$i] = new LogAiInput($level, $message);
            }
        }
        if ($inputs === []) {
            return [];
        }

        try {
            return $this->classifier->classify($inputs);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<array-key, mixed> $job
     */
    public function handle(array $job, ?LogAiResult $ai = null): void
    {
        try {
            $this->writeRaw($job);
            $this->store($job, $ai);
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
    private function store(array $job, ?LogAiResult $ai): void
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
            aiCategory: $ai?->category,
            aiSummary: $ai?->summary,
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
