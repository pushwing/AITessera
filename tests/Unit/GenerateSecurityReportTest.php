<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\GenerateSecurityReport;
use App\Domain\JwtAlgorithm;
use App\Domain\Security\DailySecurityStats;
use App\Repository\LoginEventRepositoryInterface;
use App\Service\UserService;
use App\Support\Ai\SecurityReportWriterInterface;
use App\Support\Config;
use App\Support\Queue\QueueInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class GenerateSecurityReportTest extends TestCase
{
    public function testRunAggregatesYesterdayThenQueuesReport(): void
    {
        // 실행 시각 2026-07-13 → 대상일 2026-07-12
        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->expects(self::once())->method('aggregateForDate')
            ->with('2026-07-12', 70)
            ->willReturn($this->stats('2026-07-12'));

        $writer = $this->createMock(SecurityReportWriterInterface::class);
        $writer->expects(self::once())->method('write')
            ->with(self::callback(fn (DailySecurityStats $s): bool => $s->date === '2026-07-12'))
            ->willReturn('AI 보안 리포트 본문');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->with(
            UserService::MAIL_QUEUE,
            self::callback(function (array $payload): bool {
                return $payload['type'] === 'daily_security_report'
                    && $payload['to'] === 'soc@aivance.test'
                    && is_string($payload['subject']) && str_contains($payload['subject'], '2026-07-12')
                    && $payload['body'] === 'AI 보안 리포트 본문';
            }),
        );

        $worker = new GenerateSecurityReport($queue, $repo, $writer, $this->clockAt('2026-07-13 09:00:00'), $this->config('soc@aivance.test'));

        self::assertSame(1, $worker->run());
    }

    public function testRunUsesStatisticalFallbackWhenAiReturnsNull(): void
    {
        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->method('aggregateForDate')->willReturn($this->stats('2026-07-12'));

        $writer = $this->createMock(SecurityReportWriterInterface::class);
        $writer->method('write')->willReturn(null);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->with(
            UserService::MAIL_QUEUE,
            self::callback(function (array $payload): bool {
                return $payload['type'] === 'daily_security_report'
                    && is_string($payload['body'])
                    && str_contains($payload['body'], '2026-07-12')
                    && str_contains($payload['body'], 'victim@x.test');
            }),
        );

        $worker = new GenerateSecurityReport($queue, $repo, $writer, $this->clockAt('2026-07-13 09:00:00'), $this->config('soc@aivance.test'));

        self::assertSame(1, $worker->run());
    }

    public function testRunFallsBackToLogReportRecipient(): void
    {
        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->method('aggregateForDate')->willReturn($this->stats('2026-07-12'));

        $writer = $this->createMock(SecurityReportWriterInterface::class);
        $writer->method('write')->willReturn('본문');

        $queue = $this->createMock(QueueInterface::class);
        // SECURITY_ALERT_RECIPIENT 미설정 → LOG_REPORT_RECIPIENT 로 폴백
        $queue->expects(self::once())->method('push')->with(
            UserService::MAIL_QUEUE,
            self::callback(fn (array $payload): bool => $payload['to'] === 'ops@aivance.test'),
        );

        $worker = new GenerateSecurityReport($queue, $repo, $writer, $this->clockAt('2026-07-13 09:00:00'), $this->config('', 'ops@aivance.test'));

        self::assertSame(1, $worker->run());
    }

    public function testRunSkipsWhenNoRecipientConfigured(): void
    {
        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->expects(self::never())->method('aggregateForDate');

        $writer = $this->createMock(SecurityReportWriterInterface::class);
        $writer->expects(self::never())->method('write');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $worker = new GenerateSecurityReport($queue, $repo, $writer, $this->clockAt('2026-07-13 09:00:00'), $this->config('', ''));

        self::assertSame(0, $worker->run());
    }

    private function stats(string $date): DailySecurityStats
    {
        return new DailySecurityStats(
            date: $date,
            totalAttempts: 40,
            failedAttempts: 35,
            anomalyCount: 3,
            maxScore: 92,
            topAccounts: [['email' => 'victim@x.test', 'failures' => 30, 'attempts' => 33]],
            topIps: [['ip' => '10.0.0.9', 'attempts' => 20]],
        );
    }

    private function clockAt(string $datetime): ClockInterface
    {
        return new class ($datetime) implements ClockInterface {
            public function __construct(private readonly string $datetime)
            {
            }

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->datetime);
            }
        };
    }

    private function config(string $securityRecipient, string $logRecipient = ''): Config
    {
        return new Config(
            appEnv: 'testing',
            appDebug: true,
            dbHost: '',
            dbPort: 3306,
            dbName: '',
            dbUser: '',
            dbPass: '',
            redisHost: '',
            redisPort: 6379,
            jwtSecret: 'test_secret_key_at_least_32_characters_long_xx',
            jwtAlgo: JwtAlgorithm::HS256,
            jwtAccessTtl: 900,
            jwtRefreshTtl: 1209600,
            emailVerifyTtl: 86400,
            appBaseUrl: 'http://localhost:9300/',
            logReportRecipient: $logRecipient,
            securityAlertRecipient: $securityRecipient,
        );
    }
}
