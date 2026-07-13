<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ai\LogAiInput;
use App\Support\Ai\ClaudeLogAiClassifier;
use App\Support\Ai\NullLogAiClassifier;
use PHPUnit\Framework\TestCase;

final class LogAiClassifierTest extends TestCase
{
    public function testNullClassifierReturnsEmpty(): void
    {
        $classifier = new NullLogAiClassifier();

        self::assertSame([], $classifier->classify([
            0 => new LogAiInput('error', 'boom'),
        ]));
    }

    public function testClaudeClassifierParsesResponseAndPreservesOriginalKeys(): void
    {
        // Anthropic Messages 응답을 흉내 낸 고정 응답 — content[0].text 안에 JSON 배열
        $text = json_encode([
            ['id' => 0, 'category' => 'database', 'summary' => 'DB 연결 타임아웃'],
            ['id' => 1, 'category' => 'auth', 'summary' => '토큰 서명 검증 실패'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $response = json_encode([
            'content' => [['type' => 'text', 'text' => $text]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $classifier = $this->fakeClaude($response);

        // 입력 키를 비연속(3, 7)으로 줘도 원본 키로 되돌아와야 한다
        $results = $classifier->classify([
            3 => new LogAiInput('error', 'DB timeout'),
            7 => new LogAiInput('critical', 'JWT invalid'),
        ]);

        self::assertArrayHasKey(3, $results);
        self::assertArrayHasKey(7, $results);
        self::assertSame('database', $results[3]->category);
        self::assertSame('DB 연결 타임아웃', $results[3]->summary);
        self::assertSame('auth', $results[7]->category);
    }

    public function testClaudeClassifierStripsMarkdownCodeFence(): void
    {
        $text = "다음은 분석 결과입니다:\n```json\n"
            . '[{"id": 0, "category": "network", "summary": "게이트웨이 타임아웃"}]'
            . "\n```";
        $response = json_encode([
            'content' => [['type' => 'text', 'text' => $text]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $results = $this->fakeClaude($response)->classify([0 => new LogAiInput('error', 'gw')]);

        self::assertSame('network', $results[0]->category);
    }

    public function testClaudeClassifierReturnsEmptyOnRequestFailure(): void
    {
        // request() 가 null(타임아웃·비정상 응답)을 반환하면 빈 결과
        $results = $this->fakeClaude(null)->classify([0 => new LogAiInput('error', 'boom')]);

        self::assertSame([], $results);
    }

    public function testClaudeClassifierReturnsEmptyOnMalformedResponse(): void
    {
        $results = $this->fakeClaude('not json at all')->classify([0 => new LogAiInput('error', 'boom')]);

        self::assertSame([], $results);
    }

    public function testClaudeClassifierDropsItemsWithUnknownId(): void
    {
        // 모델이 입력에 없는 id(99)를 반환하면 무시한다
        $text = json_encode([
            ['id' => 99, 'category' => 'network', 'summary' => '유령'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $response = json_encode([
            'content' => [['type' => 'text', 'text' => $text]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $results = $this->fakeClaude($response)->classify([0 => new LogAiInput('error', 'boom')]);

        self::assertSame([], $results);
    }

    /**
     * request() 를 고정 응답으로 대체한 테스트용 Claude 분류기.
     */
    private function fakeClaude(?string $response): ClaudeLogAiClassifier
    {
        return new class ('test-key', 'claude-haiku-4-5-20251001', 5, $response) extends ClaudeLogAiClassifier {
            public function __construct(
                string $apiKey,
                string $model,
                int $timeout,
                private readonly ?string $cannedResponse,
            ) {
                parent::__construct($apiKey, $model, $timeout);
            }

            protected function request(string $body): ?string
            {
                return $this->cannedResponse;
            }
        };
    }
}
