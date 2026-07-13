<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\ProcessLoginAnomaly;
use App\Domain\JwtAlgorithm;
use App\Domain\Security\AnomalyScore;
use App\Domain\Security\LoginEventSignals;
use App\Repository\LoginEventRepositoryInterface;
use App\Service\AuthService;
use App\Service\UserService;
use App\Support\Ai\LoginAnomalyScorerInterface;
use App\Support\Config;
use App\Support\Cooldown\CooldownInterface;
use App\Support\Cooldown\InMemoryCooldown;
use App\Support\Queue\QueueInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;

final class ProcessLoginAnomalyTest extends TestCase
{
    private string $anomalyDir;
    private string $deadDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/aitessera-security-' . uniqid('', true);
        $this->anomalyDir = $base . '/security';
        $this->deadDir = $base . '/dead';
    }

    protected function tearDown(): void
    {
        foreach ([$this->anomalyDir, $this->deadDir] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function testRunDrainsQueueAndStoresScoredEvents(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['email' => 'a@x.test', 'ip' => '10.0.0.1', 'user_agent' => 'UA', 'success' => false, 'occurred_at' => '2026-07-13 09:00:00'],
            ['email' => 'a@x.test', 'ip' => '10.0.0.2', 'user_agent' => 'UA', 'success' => true, 'occurred_at' => '2026-07-13 09:01:00'],
            null,
        );

        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('insert')->willReturn(1, 2);
        $repo->method('signalsFor')->willReturn(new LoginEventSignals(1, 0, 1));
        $repo->expects(self::exactly(2))->method('updateScore');

        $scorer = $this->createMock(LoginAnomalyScorerInterface::class);
        $scorer->method('score')->willReturn(new AnomalyScore(0, '정상'));

        self::assertSame(2, $this->worker($queue, $repo, $scorer)->run());
        self::assertFalse(is_dir($this->anomalyDir), '정상 이벤트는 이상 로그를 남기지 않아야 한다');
    }

    public function testAnomalyAboveThresholdIsLogged(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['email' => 'victim@x.test', 'ip' => '10.0.0.9', 'user_agent' => 'UA', 'success' => true, 'occurred_at' => '2026-07-13 09:00:00'],
            null,
        );

        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->method('insert')->willReturn(1);
        $repo->method('signalsFor')->willReturn(new LoginEventSignals(1, 5, 6));
        // 스코어러 결과가 임계값(70) 이상이면 이벤트 행에 점수·근거가 기록되어야 한다
        $repo->expects(self::once())->method('updateScore')->with(1, 90, self::stringContains('스터핑'));

        $scorer = $this->createMock(LoginAnomalyScorerInterface::class);
        $scorer->method('score')->willReturn(new AnomalyScore(90, '실패 급증 후 성공 — 스터핑 의심'));

        self::assertSame(1, $this->worker($queue, $repo, $scorer)->run());

        $logged = $this->readDir($this->anomalyDir);
        self::assertStringContainsString('victim@x.test', $logged);
        self::assertStringContainsString('스터핑', $logged);
    }

    public function testMalformedJobGoesToDeadLetterWithoutScoring(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['ip' => '10.0.0.1'], // email 누락 → 처리 불가
            null,
        );

        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->expects(self::never())->method('insert');

        $scorer = $this->createMock(LoginAnomalyScorerInterface::class);
        $scorer->expects(self::never())->method('score');

        self::assertSame(1, $this->worker($queue, $repo, $scorer)->run());
        self::assertStringContainsString('email', $this->readDir($this->deadDir));
    }

    public function testUsesLoginEventQueueChannel(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::atLeastOnce())->method('pop')
            ->with(AuthService::LOGIN_EVENT_QUEUE)
            ->willReturn(null);

        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $scorer = $this->createMock(LoginAnomalyScorerInterface::class);

        self::assertSame(0, $this->worker($queue, $repo, $scorer)->run());
    }

    public function testAlertQueuedWhenAnomalyAndRecipientConfigured(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['email' => 'victim@x.test', 'ip' => '10.0.0.9', 'user_agent' => 'UA', 'success' => true, 'occurred_at' => '2026-07-13 09:00:00'],
            null,
        );
        // 임계값 초과 이벤트는 login_anomaly_alert 메일 잡으로 큐에 적재되어야 한다
        $queue->expects(self::once())->method('push')->with(
            UserService::MAIL_QUEUE,
            self::callback(function (array $payload): bool {
                return $payload['type'] === 'login_anomaly_alert'
                    && $payload['to'] === 'soc@aivance.test'
                    && is_string($payload['subject']) && str_contains($payload['subject'], 'victim@x.test')
                    && is_string($payload['body']) && str_contains($payload['body'], '스터핑');
            }),
        );

        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->method('insert')->willReturn(1);
        $repo->method('signalsFor')->willReturn(new LoginEventSignals(1, 5, 6));

        $scorer = $this->createMock(LoginAnomalyScorerInterface::class);
        $scorer->method('score')->willReturn(new AnomalyScore(90, '실패 급증 후 성공 — 스터핑 의심'));

        self::assertSame(1, $this->worker($queue, $repo, $scorer, new InMemoryCooldown(), 'soc@aivance.test')->run());
    }

    public function testAlertSuppressedByCooldownForSameAccountAndIp(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['email' => 'victim@x.test', 'ip' => '10.0.0.9', 'user_agent' => 'UA', 'success' => false, 'occurred_at' => '2026-07-13 09:00:00'],
            ['email' => 'victim@x.test', 'ip' => '10.0.0.9', 'user_agent' => 'UA', 'success' => false, 'occurred_at' => '2026-07-13 09:00:30'],
            null,
        );
        // 같은 계정+IP 두 이벤트지만 쿨다운으로 알림은 최초 1회만 나가야 한다
        $queue->expects(self::once())->method('push');

        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->method('insert')->willReturn(1, 2);
        $repo->method('signalsFor')->willReturn(new LoginEventSignals(1, 5, 6));

        $scorer = $this->createMock(LoginAnomalyScorerInterface::class);
        $scorer->method('score')->willReturn(new AnomalyScore(90, '스터핑 의심'));

        self::assertSame(2, $this->worker($queue, $repo, $scorer, new InMemoryCooldown(), 'soc@aivance.test')->run());
    }

    public function testNoAlertWhenRecipientNotConfigured(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pop')->willReturnOnConsecutiveCalls(
            ['email' => 'victim@x.test', 'ip' => '10.0.0.9', 'user_agent' => 'UA', 'success' => true, 'occurred_at' => '2026-07-13 09:00:00'],
            null,
        );
        // 수신자 미설정 → 알림 미발송
        $queue->expects(self::never())->method('push');

        $repo = $this->createMock(LoginEventRepositoryInterface::class);
        $repo->method('insert')->willReturn(1);
        $repo->method('signalsFor')->willReturn(new LoginEventSignals(1, 5, 6));

        $scorer = $this->createMock(LoginAnomalyScorerInterface::class);
        $scorer->method('score')->willReturn(new AnomalyScore(90, '스터핑 의심'));

        self::assertSame(1, $this->worker($queue, $repo, $scorer, new InMemoryCooldown(), '')->run());
    }

    private function worker(
        QueueInterface $queue,
        LoginEventRepositoryInterface $repo,
        LoginAnomalyScorerInterface $scorer,
        ?CooldownInterface $cooldown = null,
        string $recipient = '',
    ): ProcessLoginAnomaly {
        $clock = new FixedClock(new DateTimeImmutable('2026-07-13 09:05:00'));

        return new ProcessLoginAnomaly(
            $queue,
            $repo,
            $scorer,
            $cooldown ?? new InMemoryCooldown(),
            $this->config($recipient),
            $clock,
            $this->anomalyDir,
            $this->deadDir,
        );
    }

    private function config(string $recipient = ''): Config
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
            securityAlertRecipient: $recipient,
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
