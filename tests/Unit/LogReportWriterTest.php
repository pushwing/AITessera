<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Log\DailyLogStats;
use App\Support\Ai\ClaudeLogReportWriter;
use App\Support\Ai\NullLogReportWriter;
use PHPUnit\Framework\TestCase;

final class LogReportWriterTest extends TestCase
{
    public function testNullWriterReturnsNull(): void
    {
        $writer = new NullLogReportWriter();

        self::assertNull($writer->write($this->stats('2026-07-12'), $this->stats('2026-07-11')));
    }

    public function testClaudeWriterExtractsReportText(): void
    {
        // Anthropic Messages 응답을 흉내 낸 고정 응답 — content[0].text 에 자연어 리포트
        $report = '어제 error 로그가 전일 대비 3배 급증했습니다. 주요 원인은 결제 타임아웃으로 추정됩니다.';
        $response = json_encode([
            'content' => [['type' => 'text', 'text' => $report]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $result = $this->fakeClaude($response)->write(
            $this->stats('2026-07-12', ['error' => 38]),
            $this->stats('2026-07-11', ['error' => 12]),
        );

        self::assertSame($report, $result);
    }

    public function testClaudeWriterTrimsSurroundingWhitespace(): void
    {
        $response = json_encode([
            'content' => [['type' => 'text', 'text' => "\n\n  리포트 본문  \n"]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertSame('리포트 본문', $this->fakeClaude($response)->write($this->stats('2026-07-12'), $this->stats('2026-07-11')));
    }

    public function testClaudeWriterReturnsNullOnRequestFailure(): void
    {
        // request() 가 null(타임아웃·비정상 응답)을 반환하면 null
        self::assertNull($this->fakeClaude(null)->write($this->stats('2026-07-12'), $this->stats('2026-07-11')));
    }

    public function testClaudeWriterReturnsNullOnMalformedResponse(): void
    {
        self::assertNull($this->fakeClaude('not json at all')->write($this->stats('2026-07-12'), $this->stats('2026-07-11')));
    }

    /**
     * @param array<string, int> $byLevel
     */
    private function stats(string $date, array $byLevel = ['info' => 1]): DailyLogStats
    {
        return new DailyLogStats($date, array_sum($byLevel), $byLevel, ['web' => array_sum($byLevel)]);
    }

    /**
     * request() 를 고정 응답으로 대체한 테스트용 Claude 리포트 작성기.
     */
    private function fakeClaude(?string $response): ClaudeLogReportWriter
    {
        return new class ('test-key', 'claude-haiku-4-5-20251001', 5, $response) extends ClaudeLogReportWriter {
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
