<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 이메일·비밀번호가 일치하지 않는 경우.
 *
 * 이메일 존재 여부를 노출하지 않도록 메시지는 항상 동일하게 유지한다.
 */
final class InvalidCredentialsException extends DomainException
{
    public function __construct(string $message = '이메일 또는 비밀번호가 올바르지 않습니다.')
    {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'INVALID_CREDENTIALS';
    }
}
