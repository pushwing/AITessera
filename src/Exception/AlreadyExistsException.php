<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 이미 존재하는 리소스를 다시 생성하려는 경우 (예: 중복 이메일 가입).
 */
final class AlreadyExistsException extends DomainException
{
    public function __construct(string $message = '이미 존재하는 리소스입니다.')
    {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'ALREADY_EXISTS';
    }
}
