<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\ProcessMailQueue;
use App\Domain\JwtAlgorithm;
use App\Support\Config;
use App\Support\Mail\MailerInterface;
use App\Support\Queue\QueueInterface;
use PHPUnit\Framework\TestCase;

final class ProcessMailQueueTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config(
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
        );
    }

    public function testDispatchSendsEmailVerificationWithLink(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('sendEmailVerification')
            ->with('user@aivance.test', 'http://localhost:9300/verify-email?token=abc123');

        $this->worker($mailer)->dispatch([
            'type' => 'email_verification',
            'to' => 'user@aivance.test',
            'token' => 'abc123',
        ]);
    }

    public function testDispatchSendsDailyLogReport(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('sendReport')
            ->with('ops@aivance.test', '[AITessera] 일일 로그 리포트 (2026-07-12)', '리포트 본문');

        $this->worker($mailer)->dispatch([
            'type' => 'daily_log_report',
            'to' => 'ops@aivance.test',
            'subject' => '[AITessera] 일일 로그 리포트 (2026-07-12)',
            'body' => '리포트 본문',
        ]);
    }

    public function testDispatchSendsDailySecurityReport(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('sendReport')
            ->with('soc@aivance.test', '[AITessera] 일일 보안 리포트 (2026-07-12)', '보안 리포트 본문');

        $this->worker($mailer)->dispatch([
            'type' => 'daily_security_report',
            'to' => 'soc@aivance.test',
            'subject' => '[AITessera] 일일 보안 리포트 (2026-07-12)',
            'body' => '보안 리포트 본문',
        ]);
    }

    public function testDispatchSendsLoginAnomalyAlert(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('sendReport')
            ->with('soc@aivance.test', '[AITessera] 로그인 이상 감지 (점수 90) — victim@x.test', '알림 본문');

        $this->worker($mailer)->dispatch([
            'type' => 'login_anomaly_alert',
            'to' => 'soc@aivance.test',
            'subject' => '[AITessera] 로그인 이상 감지 (점수 90) — victim@x.test',
            'body' => '알림 본문',
        ]);
    }

    public function testDailyLogReportWithMissingFieldsIsIgnored(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('sendReport');

        // subject·body 누락 → 발송하지 않는다
        $this->worker($mailer)->dispatch(['type' => 'daily_log_report', 'to' => 'ops@aivance.test']);
    }

    public function testUnknownJobTypeIsIgnored(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('sendEmailVerification');

        $this->worker($mailer)->dispatch(['type' => 'unknown']);
    }

    public function testRunDrainsQueueAndCountsProcessed(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['type' => 'email_verification', 'to' => 'a@aivance.test', 'token' => 't1'],
            ['type' => 'email_verification', 'to' => 'b@aivance.test', 'token' => 't2'],
            null,
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))->method('sendEmailVerification');

        self::assertSame(2, (new ProcessMailQueue($queue, $mailer, $this->config))->run());
    }

    private function worker(MailerInterface $mailer): ProcessMailQueue
    {
        return new ProcessMailQueue($this->createMock(QueueInterface::class), $mailer, $this->config);
    }
}
