<?php

declare(strict_types=1);

namespace App\Console;

use App\Service\UserService;
use App\Support\Config;
use App\Support\Mail\MailerInterface;
use App\Support\Queue\QueueInterface;

/**
 * 메일 큐 컨슈머 — `mail_queue` 를 비우며 각 작업을 Mailer 로 발송한다.
 *
 * `bin/console mail:work` 로 실행하며, cron / systemd timer 로 주기 기동한다.
 */
final readonly class ProcessMailQueue
{
    public function __construct(
        private QueueInterface $queue,
        private MailerInterface $mailer,
        private Config $config,
    ) {
    }

    /**
     * 큐가 빌 때까지 순차 처리하고, 처리한 작업 수를 반환한다.
     */
    public function run(): int
    {
        $processed = 0;
        while (($job = $this->queue->pop(UserService::MAIL_QUEUE)) !== null) {
            $this->dispatch($job);
            ++$processed;
        }

        return $processed;
    }

    /**
     * @param array<array-key, mixed> $job
     */
    public function dispatch(array $job): void
    {
        $type = $job['type'] ?? null;

        match ($type) {
            'email_verification' => $this->sendEmailVerification($job),
            'daily_log_report' => $this->sendDailyLogReport($job),
            default => null, // 알 수 없는 타입은 무시 (dead-letter 처리는 후속 작업)
        };
    }

    /**
     * @param array<array-key, mixed> $job
     */
    private function sendDailyLogReport(array $job): void
    {
        $to = $job['to'] ?? null;
        $subject = $job['subject'] ?? null;
        $body = $job['body'] ?? null;
        if (!is_string($to) || !is_string($subject) || !is_string($body)) {
            return;
        }

        $this->mailer->sendReport($to, $subject, $body);
    }

    /**
     * @param array<array-key, mixed> $job
     */
    private function sendEmailVerification(array $job): void
    {
        $to = $job['to'] ?? null;
        $token = $job['token'] ?? null;
        if (!is_string($to) || !is_string($token)) {
            return;
        }

        // 사용자가 클릭하는 링크는 프론트엔드 인증 페이지를 가리킨다. 프론트엔드가 토큰을
        // 추출해 `POST /api/v1/users/verify` API 로 전달한다(API 엔드포인트를 직접 링크하지 않음).
        $link = rtrim($this->config->appBaseUrl, '/') . '/verify-email?token=' . $token;
        $this->mailer->sendEmailVerification($to, $link);
    }
}
