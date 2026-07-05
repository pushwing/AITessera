<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 요청한 리소스·경로를 찾을 수 없는 경우.
 */
final class NotFoundException extends DomainException
{
    public function __construct(string $message = '요청한 리소스를 찾을 수 없습니다.')
    {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'NOT_FOUND';
    }
}
