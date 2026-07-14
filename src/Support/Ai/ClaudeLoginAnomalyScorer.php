<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Security\AnomalyScore;
use App\Domain\Security\LoginEventSignals;
use JsonException;

/**
 * Claude(Anthropic Messages API) 기반 로그인 이상 스코어러(하이브리드).
 *
 * 규칙 신호(분산 IP·실패 급증·성공 여부)를 근거로 Claude 에 종합 이상 점수(0~100)와 한 줄
 * 근거를 요청한다. 외부 호출은 타임아웃을 걸고, 실패·비정상 응답·파싱 오류는 모두 규칙 기반
 * 폴백(RuleLoginAnomalyScorer)으로 흡수한다 — 스코어링이 항상 결과를 내도록 보장한다.
 * API 키·모델·타임아웃과 폴백 스코어러는 DI 로 주입한다.
 */
class ClaudeLoginAnomalyScorer implements LoginAnomalyScorerInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';
    private const string API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly LoginAnomalyScorerInterface $fallback,
        private readonly int $timeout = 5,
        private readonly int $maxTokens = 256,
    ) {
    }

    public function score(bool $success, LoginEventSignals $signals): AnomalyScore
    {
        $body = $this->buildRequestBody($success, $signals);
        if ($body === null) {
            return $this->fallback->score($success, $signals);
        }

        $response = $this->request($body);
        if ($response === null) {
            return $this->fallback->score($success, $signals);
        }

        return $this->parseResponse($response) ?? $this->fallback->score($success, $signals);
    }

    /**
     * 실제 HTTP 호출. 성공 시 응답 본문(JSON 문자열), 실패 시 null 을 반환한다.
     *
     * 테스트에서 오버라이드해 외부 호출 없이 프롬프트 조립·응답 파싱 로직을 검증할 수 있도록
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

    private function buildRequestBody(bool $success, LoginEventSignals $signals): ?string
    {
        try {
            $outcome = $success ? '성공' : '실패';
            $prompt = <<<PROMPT
                다음은 한 계정에 대한 최근 15분 로그인 시도 집계다. 계정 탈취(크리덴셜 스터핑·
                분산 브루트포스) 위험을 0~100 정수로 평가하라(0=정상, 100=강한 이상).

                - 이번 시도 결과: {$outcome}
                - 서로 다른 IP 수: {$signals->distinctIpCount}
                - 실패 시도 수: {$signals->failureCount}
                - 전체 시도 수: {$signals->attemptCount}

                판단 지침: 동일 계정을 여러 IP 에서 시도, 실패가 급증, 실패 급증 직후 성공은
                높은 위험이다.

                반드시 아래 형식의 JSON 객체만 출력하라(설명·마크다운·코드펜스 금지).
                {"score": <0~100 정수>, "reason": "<한국어 한 줄 근거(80자 이내)>"}
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
     * Claude 응답에서 {score, reason} 객체를 뽑아 AnomalyScore 로 만든다.
     * 파싱 불가·형식 오류 시 null 을 반환해 호출측이 폴백하도록 한다.
     */
    private function parseResponse(string $response): ?AnomalyScore
    {
        $text = $this->extractText($response);
        if ($text === null) {
            return null;
        }

        $item = $this->decodeResultObject($text);
        if ($item === null) {
            return null;
        }

        $score = $item['score'] ?? null;
        $reason = $item['reason'] ?? null;
        if (!is_numeric($score) || !is_string($reason) || $reason === '') {
            return null;
        }

        return new AnomalyScore(max(0, min(100, (int) $score)), $reason);
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
     * 모델이 생성한 텍스트에서 JSON 객체를 디코드한다. 코드펜스로 감싸는 경우를 대비해
     * 첫 `{` 부터 마지막 `}` 까지를 잘라낸다.
     *
     * @return array<array-key, mixed>|null
     */
    private function decodeResultObject(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $json = substr($text, $start, $end - $start + 1);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
