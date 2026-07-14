<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Security\AnomalyScore;
use App\Repository\LoginEventRepositoryInterface;
use App\Service\AuthService;
use App\Service\UserService;
use App\Support\Ai\LoginAnomalyScorerInterface;
use App\Support\Config;
use App\Support\Cooldown\CooldownInterface;
use App\Support\Queue\QueueInterface;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

/**
 * 로그인 이상 탐지 워커 — `login_event_queue` 를 비우며 각 인증 이벤트를 스코어링한다.
 *
 * 1. 이벤트를 login_events 테이블에 저장 (email·ip·user_agent·성공여부, 민감정보 제외)
 * 2. 해당 계정의 최근 윈도우 집계 신호를 조회 → 하이브리드 스코어러로 이상 점수 산출
 * 3. 점수·근거를 이벤트 행에 기록
 * 4. 임계값(ANOMALY_SCORE_THRESHOLD) 이상이면 이상징후 로그에 append (감사·후속 조치용)
 * 5. 처리 실패 → var/logs/queue-failed (dead-letter)
 *
 * 스코어링은 요청 사이클 밖(비동기)에서만 수행한다 — 로그인 응답 지연 금지.
 * `bin/console security:scan` 으로 실행하며 cron / systemd timer 로 주기 기동한다.
 */
final readonly class ProcessLoginAnomaly
{
    /** 이상 신호를 집계할 시간 윈도우(분). */
    private const int WINDOW_MINUTES = 15;

    private string $anomalyDir;
    private string $deadLetterDir;

    public function __construct(
        private QueueInterface $queue,
        private LoginEventRepositoryInterface $events,
        private LoginAnomalyScorerInterface $scorer,
        private CooldownInterface $cooldown,
        private Config $config,
        private ClockInterface $clock,
        ?string $anomalyDir = null,
        ?string $deadLetterDir = null,
    ) {
        $root = dirname(__DIR__, 2);
        $this->anomalyDir = $anomalyDir ?? $root . '/var/logs/security';
        $this->deadLetterDir = $deadLetterDir ?? $root . '/var/logs/queue-failed';
    }

    /**
     * 큐가 빌 때까지 처리하고 처리 건수를 반환한다.
     */
    public function run(): int
    {
        $count = 0;
        while (($job = $this->queue->pop(AuthService::LOGIN_EVENT_QUEUE)) !== null) {
            $this->handle($job);
            ++$count;
        }

        return $count;
    }

    /**
     * @param array<array-key, mixed> $job
     */
    public function handle(array $job): void
    {
        try {
            $this->process($job);
        } catch (Throwable $e) {
            $this->deadLetter($job, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $job
     */
    private function process(array $job): void
    {
        $email = $job['email'] ?? null;
        $ip = $job['ip'] ?? null;
        if (!is_string($email) || $email === '' || !is_string($ip) || $ip === '') {
            throw new RuntimeException('로그인 이벤트에 email·ip 가 필요합니다.');
        }

        $uaRaw = $job['user_agent'] ?? null;
        $userAgent = is_string($uaRaw) && $uaRaw !== '' ? $uaRaw : null;
        $success = (bool) ($job['success'] ?? false);
        $occurredAt = $this->normalizeDate($job['occurred_at'] ?? null)
            ?? $this->clock->now()->format('Y-m-d H:i:s');

        $id = $this->events->insert($email, $ip, $userAgent, $success, $occurredAt);

        $since = $this->clock->now()
            ->modify('-' . self::WINDOW_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');
        $signals = $this->events->signalsFor($email, $since);
        $result = $this->scorer->score($success, $signals);

        $this->events->updateScore($id, $result->score, $result->reason);

        if ($result->score >= $this->config->anomalyScoreThreshold) {
            $this->writeAnomaly($email, $ip, $userAgent, $success, $occurredAt, $result);
            $this->maybeQueueAlert($email, $ip, $userAgent, $occurredAt, $result);
        }
    }

    /**
     * 임계값 초과 이벤트를 실시간 메일 알림으로 큐에 적재한다 — 단, 폭주 방지를 위해
     * 같은 계정+IP 는 쿨다운(ANOMALY_ALERT_COOLDOWN) 내 최초 1회만 발송한다.
     *
     * 수신자 미설정이면 스킵한다. 쿨다운 저장소 조회가 실패하면 알림 누락을 막기 위해
     * fail-open 으로 발송을 진행한다(보안 알림은 놓치는 것보다 중복이 낫다).
     */
    private function maybeQueueAlert(
        string $email,
        string $ip,
        ?string $userAgent,
        string $occurredAt,
        AnomalyScore $result,
    ): void {
        $recipient = $this->config->resolvedSecurityRecipient();
        if ($recipient === '') {
            return;
        }

        $key = 'login_anomaly:' . sha1($email . '|' . $ip);
        try {
            $fresh = $this->cooldown->acquire($key, $this->config->anomalyAlertCooldown);
        } catch (Throwable) {
            $fresh = true;
        }
        if (!$fresh) {
            return;
        }

        $this->queue->push(UserService::MAIL_QUEUE, [
            'type' => 'login_anomaly_alert',
            'to' => $recipient,
            'subject' => sprintf('[AITessera] 로그인 이상 감지 (점수 %d) — %s', $result->score, $email),
            'body' => $this->alertBody($email, $ip, $userAgent, $occurredAt, $result),
        ]);
    }

    /**
     * 실시간 알림 본문 — anomaly_reason 이 이미 산출돼 있으므로 AI 없이 구조화 템플릿으로 조립한다.
     */
    private function alertBody(
        string $email,
        string $ip,
        ?string $userAgent,
        string $occurredAt,
        AnomalyScore $result,
    ): string {
        return sprintf(
            "로그인 이상 이벤트가 감지되었습니다.\n\n시각: %s\n계정: %s\nIP: %s\nUser-Agent: %s\n이상 점수: %d\n근거: %s",
            $occurredAt,
            $email,
            $ip,
            $userAgent ?? '(없음)',
            $result->score,
            $result->reason,
        );
    }

    private function writeAnomaly(
        string $email,
        string $ip,
        ?string $userAgent,
        bool $success,
        string $occurredAt,
        AnomalyScore $result,
    ): void {
        $line = json_encode([
            'occurred_at' => $occurredAt,
            'email' => $email,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'success' => $success,
            'score' => $result->score,
            'reason' => $result->reason,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->append($this->anomalyDir, 'anomaly-' . date('Y-m-d') . '.log', $line === false ? '{}' : $line);
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
