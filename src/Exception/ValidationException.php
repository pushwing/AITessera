<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 입력값 유효성 검사 실패.
 */
final class ValidationException extends DomainException
{
    /**
     * @var list<string>
     */
    private array $errors;

    /**
     * @param list<string> $errors
     */
    public function __construct(array $errors = [])
    {
        $this->errors = $errors;
        parent::__construct($errors[0] ?? '입력값 검증에 실패했습니다.');
    }

    public function httpStatusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'VALIDATION_ERROR';
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
