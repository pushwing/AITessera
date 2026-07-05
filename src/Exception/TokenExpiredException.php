<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 토큰이 만료된 경우.
 */
final class TokenExpiredException extends DomainException
{
    public function __construct(string $message = '토큰이 만료되었습니다.')
    {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'TOKEN_EXPIRED';
    }
}
