<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Security\DailySecurityStats;
use App\Repository\LoginEventRepositoryInterface;
use App\Service\UserService;
use App\Support\Ai\SecurityReportWriterInterface;
use App\Support\Config;
use App\Support\Queue\QueueInterface;
use Psr\Clock\ClockInterface;

/**
 * 일일 보안 리포트 생성 워커 — 전일 login_events 를 집계·분석해 자연어 리포트를 만들고
 * 기존 메일 큐(mail_queue)에 적재한다.
 *
 * 1. 전일 로그인 이벤트를 보안 관점으로 집계 (Repository, occurred_at 범위)
 * 2. 집계 결과만 AI 작성기에 전달해 자연어 리포트 생성 (원시 이벤트 미전달 → 토큰 절약)
 * 3. AI 미가용 시 통계 기반 폴백 본문으로 대체 (graceful degradation)
 * 4. mail_queue 에 daily_security_report 잡으로 적재 → ProcessMailQueue 가 발송
 *
 * `bin/console report:security` 로 실행하며 cron / systemd timer 로 하루 1회 기동한다.
 * 수신자(SECURITY_ALERT_RECIPIENT, 없으면 LOG_REPORT_RECIPIENT)가 비어 있으면 발송을 건너뛴다.
 */
final readonly class GenerateSecurityReport
{
    public function __construct(
        private QueueInterface $queue,
        private LoginEventRepositoryInterface $events,
        private SecurityReportWriterInterface $writer,
        private ClockInterface $clock,
        private Config $config,
    ) {
    }

    /**
     * 리포트를 생성해 메일 큐에 적재하고, 적재한 리포트 수(0 또는 1)를 반환한다.
     */
    public function run(): int
    {
        $recipient = $this->config->resolvedSecurityRecipient();
        if ($recipient === '') {
            return 0;
        }

        $targetDate = $this->clock->now()->modify('-1 day')->format('Y-m-d');
        $stats = $this->events->aggregateForDate($targetDate, $this->config->anomalyScoreThreshold);

        $body = $this->writer->write($stats) ?? $this->fallbackBody($stats);

        $this->queue->push(UserService::MAIL_QUEUE, [
            'type' => 'daily_security_report',
            'to' => $recipient,
            'subject' => "[AITessera] 일일 보안 리포트 ({$targetDate})",
            'body' => $body,
        ]);

        return 1;
    }

    /**
     * AI 미가용 시 사용할 통계 기반 보안 리포트 본문 — 집계 수치만으로 조립한다.
     */
    private function fallbackBody(DailySecurityStats $stats): string
    {
        return sprintf(
            "[%s] 로그인 시도 %d건 (실패 %d건), 이상 이벤트 %d건, 최고 점수 %d.\n실패 상위 계정: %s\n시도 상위 IP: %s",
            $stats->date,
            $stats->totalAttempts,
            $stats->failedAttempts,
            $stats->anomalyCount,
            $stats->maxScore,
            $this->formatAccounts($stats->topAccounts),
            $this->formatIps($stats->topIps),
        );
    }

    /**
     * @param array<int, array{email: string, failures: int, attempts: int}> $accounts
     */
    private function formatAccounts(array $accounts): string
    {
        if ($accounts === []) {
            return '없음';
        }

        $parts = [];
        foreach ($accounts as $account) {
            $parts[] = "{$account['email']}(실패 {$account['failures']}/{$account['attempts']})";
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<int, array{ip: string, attempts: int}> $ips
     */
    private function formatIps(array $ips): string
    {
        if ($ips === []) {
            return '없음';
        }

        $parts = [];
        foreach ($ips as $ip) {
            $parts[] = "{$ip['ip']}({$ip['attempts']}회)";
        }

        return implode(', ', $parts);
    }
}
