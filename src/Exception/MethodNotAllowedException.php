<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 경로는 존재하나 허용되지 않은 HTTP 메서드로 요청한 경우.
 */
final class MethodNotAllowedException extends DomainException
{
    public function __construct(string $message = '허용되지 않은 요청 메서드입니다.')
    {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 405;
    }

    public function errorCode(): string
    {
        return 'METHOD_NOT_ALLOWED';
    }
}
