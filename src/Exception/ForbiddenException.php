<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 인증은 되었으나 해당 리소스·작업에 대한 권한이 없는 경우.
 */
final class ForbiddenException extends DomainException
{
    public function __construct(string $message = '이 작업을 수행할 권한이 없습니다.')
    {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'FORBIDDEN';
    }
}
