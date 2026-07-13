<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * AI 분류 입력 — 하나의 error/critical 로그를 AI 분류기에 넘길 때 쓰는 값 객체.
 */
final readonly class LogAiInput
{
    public function __construct(
        public string $level,
        public string $message,
    ) {
    }
}
