<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * 메일 발송 계약.
 *
 * 부트스트랩 단계에서는 dev 구현(LogMailer)만 제공하고, 실제 SMTP 발송은 후속 작업에서
 * symfony/mailer 구현으로 교체한다.
 */
interface MailerInterface
{
    /**
     * 이메일 인증 메일을 발송한다.
     */
    public function sendEmailVerification(string $to, string $verificationLink): void;
}
