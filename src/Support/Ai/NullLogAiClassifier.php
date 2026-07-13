<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * 개발·테스트용 무동작 분류기 — AI 호출 없이 항상 빈 결과를 반환한다.
 *
 * ANTHROPIC_API_KEY 가 없거나 테스트 환경일 때 바인딩되어, 외부 API 호출 없이도
 * 로그 파이프라인이 정상 동작하도록 한다(graceful degradation). 운영에서는
 * ClaudeLogAiClassifier 로 교체한다(컨테이너 바인딩만 변경).
 */
final class NullLogAiClassifier implements LogAiClassifierInterface
{
    public function classify(array $inputs): array
    {
        return [];
    }
}
