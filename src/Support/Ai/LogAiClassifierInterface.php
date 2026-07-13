<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Ai\LogAiInput;
use App\Domain\Ai\LogAiResult;

/**
 * 로그 AI 분류 계약.
 *
 * error/critical 로그를 배치로 받아 근본원인 카테고리 + 한 줄 요약을 산출한다.
 * 부트스트랩 단계에서는 dev 구현(NullLogAiClassifier)을 바인딩하고, 운영에서는 실제
 * Claude API 구현(ClaudeLogAiClassifier)으로 교체한다(컨테이너 바인딩만 변경).
 */
interface LogAiClassifierInterface
{
    /**
     * 여러 로그를 한 번의 호출로 분류·요약한다(건별 호출 금지).
     *
     * 입력 배열의 키(원본 큐 인덱스)를 결과 배열의 키로 그대로 보존한다. 분류에 실패했거나
     * 결과가 없는 항목은 키를 생략하며, 호출측은 누락 항목을 NULL 로 처리한다.
     *
     * @param array<int, LogAiInput> $inputs 원본 큐 인덱스 → 입력
     *
     * @return array<int, LogAiResult> 원본 큐 인덱스 → 결과
     */
    public function classify(array $inputs): array;
}
