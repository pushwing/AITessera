<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * 개발용 메일러 — 실제 발송 대신 메일 내용을 `var/logs/mail-YYYY-MM-DD.log` 에 기록한다.
 *
 * 운영 환경에서는 symfony/mailer 기반 SMTP 구현으로 교체한다(컨테이너 바인딩만 변경).
 */
final class LogMailer implements MailerInterface
{
    private readonly string $logDir;

    public function __construct(?string $logDir = null)
    {
        // src/Support/Mail → 프로젝트 루트 기준 var/logs
        $this->logDir = $logDir ?? dirname(__DIR__, 3) . '/var/logs';
    }

    public function sendEmailVerification(string $to, string $verificationLink): void
    {
        $this->write($to, '이메일 인증', "인증 링크: {$verificationLink}");
    }

    private function write(string $to, string $subject, string $body): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0o775, true);
        }

        $line = json_encode([
            'time' => date('c'),
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents(
            $this->logDir . '/mail-' . date('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
