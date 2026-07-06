<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 이메일 인증이 완료되지 않은 계정으로 로그인을 시도한 경우.
 */
final class EmailNotVerifiedException extends DomainException
{
    public function __construct(string $message = '이메일 인증이 필요합니다.')
    {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'EMAIL_NOT_VERIFIED';
    }
}
