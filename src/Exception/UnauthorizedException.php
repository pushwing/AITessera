<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 인증 토큰이 없거나 형식이 잘못된 경우.
 */
final class UnauthorizedException extends DomainException
{
    public function __construct(string $message = '인증이 필요합니다.')
    {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'UNAUTHORIZED';
    }
}
