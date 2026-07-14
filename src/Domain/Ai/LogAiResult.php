<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * AI 분류 결과 — 근본원인 카테고리 + 한 줄 요약(한국어).
 *
 * `client_logs.ai_category` · `ai_summary` 컬럼에 그대로 저장된다.
 */
final readonly class LogAiResult
{
    public function __construct(
        public string $category,
        public string $summary,
    ) {
    }
}
