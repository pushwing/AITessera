<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\GenerateDailyLogReport;
use App\Domain\JwtAlgorithm;
use App\Domain\Log\DailyLogStats;
use App\Repository\LogRepositoryInterface;
use App\Service\UserService;
use App\Support\Ai\LogReportWriterInterface;
use App\Support\Config;
use App\Support\Queue\QueueInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class GenerateDailyLogReportTest extends TestCase
{
    public function testRunAggregatesYesterdayAndDayBeforeThenQueuesReport(): void
    {
        // 실행 시각 2026-07-13 → 대상일 2026-07-12, 전일 2026-07-11
        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->method('aggregateByDate')->willReturnCallback(
            fn (string $date): DailyLogStats => new DailyLogStats($date, 10, ['error' => 3, 'info' => 7], ['web' => 10]),
        );

        // AI 작성기는 대상일·전일 통계를 받아 리포트를 반환한다
        $writer = $this->createMock(LogReportWriterInterface::class);
        $writer->expects(self::once())->method('write')
            ->with(
                self::callback(fn (DailyLogStats $s): bool => $s->date === '2026-07-12'),
                self::callback(fn (DailyLogStats $s): bool => $s->date === '2026-07-11'),
            )
            ->willReturn('AI 리포트 본문');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')
            ->with(
                UserService::MAIL_QUEUE,
                self::callback(function (array $payload): bool {
                    return $payload['type'] === 'daily_log_report'
                        && $payload['to'] === 'ops@aivance.test'
                        && is_string($payload['subject']) && str_contains($payload['subject'], '2026-07-12')
                        && $payload['body'] === 'AI 리포트 본문';
                }),
            );

        $worker = new GenerateDailyLogReport($queue, $repo, $writer, $this->clockAt('2026-07-13 09:00:00'), $this->config('ops@aivance.test'));

        self::assertSame(1, $worker->run());
    }

    public function testRunUsesStatisticalFallbackWhenAiReturnsNull(): void
    {
        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->method('aggregateByDate')->willReturnCallback(
            fn (string $date): DailyLogStats => new DailyLogStats($date, 10, ['error' => 3, 'info' => 7], ['web' => 10]),
        );

        // AI 미가용(null) → 통계 기반 폴백 본문을 조립해 발송한다
        $writer = $this->createMock(LogReportWriterInterface::class);
        $writer->method('write')->willReturn(null);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')
            ->with(
                UserService::MAIL_QUEUE,
                self::callback(function (array $payload): bool {
                    return $payload['type'] === 'daily_log_report'
                        && is_string($payload['body'])
                        && $payload['body'] !== ''
                        && str_contains($payload['body'], '2026-07-12')
                        && str_contains($payload['body'], 'error');
                }),
            );

        $worker = new GenerateDailyLogReport($queue, $repo, $writer, $this->clockAt('2026-07-13 09:00:00'), $this->config('ops@aivance.test'));

        self::assertSame(1, $worker->run());
    }

    public function testRunSkipsWhenNoRecipientConfigured(): void
    {
        $repo = $this->createMock(LogRepositoryInterface::class);
        $repo->expects(self::never())->method('aggregateByDate');

        $writer = $this->createMock(LogReportWriterInterface::class);
        $writer->expects(self::never())->method('write');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $worker = new GenerateDailyLogReport($queue, $repo, $writer, $this->clockAt('2026-07-13 09:00:00'), $this->config(''));

        self::assertSame(0, $worker->run());
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

    private function config(string $recipient): Config
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
            logReportRecipient: $recipient,
        );
    }
}
