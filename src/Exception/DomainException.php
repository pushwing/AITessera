<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * 도메인 예외 기반 클래스.
 *
 * 모든 도메인 예외는 HTTP 상태코드와 에러 코드(문자열)를 반드시 노출한다.
 * 전역 ErrorHandlerMiddleware 가 이를 잡아 표준 에러 응답으로 변환한다.
 */
abstract class DomainException extends RuntimeException
{
    abstract public function httpStatusCode(): int;

    abstract public function errorCode(): string;
}
