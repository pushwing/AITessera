<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Ai\LogAiInput;
use App\Domain\Ai\LogAiResult;
use JsonException;

/**
 * Claude(Anthropic Messages API) 기반 로그 분류기.
 *
 * error/critical 로그 배치를 한 번의 API 호출로 분류·요약한다. 외부 호출은 5초 타임아웃을
 * 걸고, 실패(타임아웃·비정상 응답·파싱 오류)는 모두 빈 결과로 흡수해 로그 파이프라인을
 * 중단시키지 않는다(graceful degradation). API 키·모델·타임아웃은 DI 로 주입한다.
 */
class ClaudeLogAiClassifier implements LogAiClassifierInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';
    private const string API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 5,
        private readonly int $maxTokens = 1024,
    ) {
    }

    public function classify(array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        // 원본 큐 인덱스를 순번(0..n)으로 재배열해 프롬프트에 싣고, 응답을 다시 원본 인덱스로 되돌린다.
        $indexMap = array_keys($inputs);
        $body = $this->buildRequestBody(array_values($inputs));
        if ($body === null) {
            return [];
        }

        $response = $this->request($body);
        if ($response === null) {
            return [];
        }

        return $this->parseResponse($response, $indexMap);
    }

    /**
     * 실제 HTTP 호출. 성공 시 응답 본문(JSON 문자열), 실패 시 null 을 반환한다.
     *
     * 테스트에서 오버라이드해 외부 호출 없이 buildRequestBody·parseResponse 로직을 검증할 수 있도록
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
     * 배치 입력을 Anthropic Messages API 요청 본문(JSON)으로 만든다.
     *
     * @param list<LogAiInput> $inputs
     */
    private function buildRequestBody(array $inputs): ?string
    {
        $items = [];
        foreach ($inputs as $i => $input) {
            $items[] = ['id' => $i, 'level' => $input->level, 'message' => $input->message];
        }

        try {
            $logsJson = json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            $prompt = <<<PROMPT
                다음은 운영 중 발생한 error/critical 로그 목록(JSON)이다. 각 항목을 분석해
                근본원인 카테고리와 한 줄 요약을 만들어라.

                - category: network, auth, ui-render, payload, database, permission, unknown 중
                  가장 적합한 값 하나(소문자 kebab-case)
                - summary: 한국어 한 줄 요약(80자 이내)

                반드시 아래 형식의 JSON 배열만 출력하라(설명·마크다운·코드펜스 금지).
                [{"id": <입력 id>, "category": "...", "summary": "..."}]

                로그 목록:
                {$logsJson}
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
     * Claude 응답(JSON)에서 분류 결과를 뽑아 원본 인덱스별로 매핑한다.
     *
     * @param list<int> $indexMap 순번 → 원본 큐 인덱스
     *
     * @return array<int, LogAiResult>
     */
    private function parseResponse(string $response, array $indexMap): array
    {
        $text = $this->extractText($response);
        if ($text === null) {
            return [];
        }

        $items = $this->decodeResultArray($text);
        if ($items === null) {
            return [];
        }

        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;
            $category = $item['category'] ?? null;
            $summary = $item['summary'] ?? null;

            if (!is_int($id) || !isset($indexMap[$id])) {
                continue;
            }
            if (!is_string($category) || $category === '' || !is_string($summary) || $summary === '') {
                continue;
            }

            $results[$indexMap[$id]] = new LogAiResult($category, $summary);
        }

        return $results;
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

        return $first['text'];
    }

    /**
     * 모델이 생성한 텍스트에서 JSON 배열을 디코드한다. 모델이 코드펜스로 감싸는 경우를
     * 대비해 첫 `[` 부터 마지막 `]` 까지를 잘라낸다.
     *
     * @return list<mixed>|null
     */
    private function decodeResultArray(string $text): ?array
    {
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $json = substr($text, $start, $end - $start + 1);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? array_values($decoded) : null;
    }
}
