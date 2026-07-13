<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Log\DailyLogStats;
use App\Repository\LogRepositoryInterface;
use App\Service\UserService;
use App\Support\Ai\LogReportWriterInterface;
use App\Support\Config;
use App\Support\Queue\QueueInterface;
use Psr\Clock\ClockInterface;

/**
 * 일일 로그 리포트 생성 워커 — 전일 client_logs 를 집계·분석해 자연어 리포트를 만들고
 * 기존 메일 큐(mail_queue)에 적재한다.
 *
 * 1. 전일·전전일 로그를 레벨별·소스별로 집계 (Repository 집계 쿼리, created_at 범위)
 * 2. 집계 결과만 AI 작성기에 전달해 자연어 리포트 생성 (원시 로그 미전달 → 토큰 절약)
 * 3. AI 미가용 시 통계 기반 폴백 본문으로 대체 (graceful degradation)
 * 4. mail_queue 에 daily_log_report 잡으로 적재 → ProcessMailQueue 가 발송
 *
 * `bin/console report:daily` 로 실행하며 cron / systemd timer 로 하루 1회 기동한다.
 * 수신자(LOG_REPORT_RECIPIENT)가 비어 있으면 발송을 건너뛴다.
 */
final readonly class GenerateDailyLogReport
{
    public function __construct(
        private QueueInterface $queue,
        private LogRepositoryInterface $logs,
        private LogReportWriterInterface $writer,
        private ClockInterface $clock,
        private Config $config,
    ) {
    }

    /**
     * 리포트를 생성해 메일 큐에 적재하고, 적재한 리포트 수(0 또는 1)를 반환한다.
     */
    public function run(): int
    {
        $recipient = $this->config->logReportRecipient;
        if ($recipient === '') {
            return 0;
        }

        $now = $this->clock->now();
        $targetDate = $now->modify('-1 day')->format('Y-m-d');
        $priorDate = $now->modify('-2 day')->format('Y-m-d');

        $target = $this->logs->aggregateByDate($targetDate);
        $prior = $this->logs->aggregateByDate($priorDate);

        $body = $this->writer->write($target, $prior) ?? $this->fallbackBody($target, $prior);

        $this->queue->push(UserService::MAIL_QUEUE, [
            'type' => 'daily_log_report',
            'to' => $recipient,
            'subject' => "[AITessera] 일일 로그 리포트 ({$targetDate})",
            'body' => $body,
        ]);

        return 1;
    }

    /**
     * AI 미가용 시 사용할 통계 기반 리포트 본문 — 집계 수치만으로 조립한다.
     */
    private function fallbackBody(DailyLogStats $target, DailyLogStats $prior): string
    {
        return sprintf(
            "[%s] 총 %d건 (전일 %d건).\n레벨별: %s\n소스별: %s",
            $target->date,
            $target->total,
            $prior->total,
            $this->formatCounts($target->byLevel),
            $this->formatCounts($target->bySource),
        );
    }

    /**
     * @param array<string, int> $counts
     */
    private function formatCounts(array $counts): string
    {
        if ($counts === []) {
            return '없음';
        }

        $parts = [];
        foreach ($counts as $key => $count) {
            $parts[] = "{$key}={$count}";
        }

        return implode(', ', $parts);
    }
}
