<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Log\DailyLogStats;
use JsonException;

/**
 * Claude(Anthropic Messages API) 기반 일일 로그 리포트 작성기.
 *
 * 하루치 집계 통계(대상일 + 전일)를 한 번의 API 호출로 자연어 운영 리포트로 변환한다.
 * 원시 로그가 아닌 집계 결과만 전달해 토큰을 아끼고, 하루 1회 호출로 빈도를 최소화한다.
 * 외부 호출은 타임아웃을 걸고, 실패(타임아웃·비정상 응답·파싱 오류)는 NULL 로 흡수해
 * 리포트 파이프라인을 중단시키지 않는다(graceful degradation). 키·모델·타임아웃은 DI 주입.
 */
class ClaudeLogReportWriter implements LogReportWriterInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';
    private const string API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 5,
        private readonly int $maxTokens = 2048,
    ) {
    }

    public function write(DailyLogStats $target, DailyLogStats $previous): ?string
    {
        $body = $this->buildRequestBody($target, $previous);
        if ($body === null) {
            return null;
        }

        $response = $this->request($body);
        if ($response === null) {
            return null;
        }

        $text = $this->extractText($response);

        return $text === null || $text === '' ? null : $text;
    }

    /**
     * 실제 HTTP 호출. 성공 시 응답 본문(JSON 문자열), 실패 시 NULL 을 반환한다.
     *
     * 테스트에서 오버라이드해 외부 호출 없이 buildRequestBody·extractText 로직을 검증할 수 있도록
     * protected 로 노출한다.
     */
    protected function request(string $body): ?string
    {
        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
        ]);

        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // PHP 8+ 에서 curl_close 는 no-op(핸들은 GC 로 해제)이라 호출하지 않는다.

        if (!is_string($result) || $status < 200 || $status >= 300) {
            return null;
        }

        return $result;
    }

    /**
     * 집계 통계를 Anthropic Messages API 요청 본문(JSON)으로 만든다.
     */
    private function buildRequestBody(DailyLogStats $target, DailyLogStats $previous): ?string
    {
        try {
            $statsJson = json_encode(
                [
                    'target' => ['date' => $target->date, 'total' => $target->total, 'by_level' => $target->byLevel, 'by_source' => $target->bySource],
                    'previous' => ['date' => $previous->date, 'total' => $previous->total, 'by_level' => $previous->byLevel, 'by_source' => $previous->bySource],
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );

            $prompt = <<<PROMPT
                다음은 운영 로그의 하루치 집계 통계(JSON)다. target 은 리포트 대상일, previous 는
                전일이다. 각 날짜에 레벨별(by_level)·소스별(by_source) 건수와 총합(total)이 있다.

                이 통계만으로 운영 담당자가 읽을 한국어 일일 리포트를 작성하라.
                - 전일 대비 증감이 큰 항목(특히 error/critical 급증)을 우선 언급하고 추정 원인을 덧붙여라.
                - 특이사항이 없으면 안정적이라고 명확히 밝혀라.
                - 서두·맺음말 없이 리포트 본문만, 마크다운 없이 3~6문장으로 간결하게.

                집계 통계:
                {$statsJson}
                PROMPT;

            return json_encode([
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Anthropic Messages 응답 구조에서 모델이 생성한 텍스트(content[0].text)를 추출한다.
     */
    private function extractText(string $response): ?string
    {
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['content']) || !is_array($decoded['content'])) {
            return null;
        }

        $first = $decoded['content'][0] ?? null;
        if (!is_array($first) || !isset($first['text']) || !is_string($first['text'])) {
            return null;
        }

        return trim($first['text']);
    }
}
