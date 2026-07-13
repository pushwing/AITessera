<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Security\DailySecurityStats;
use App\Support\Ai\ClaudeSecurityReportWriter;
use App\Support\Ai\NullSecurityReportWriter;
use PHPUnit\Framework\TestCase;

final class SecurityReportWriterTest extends TestCase
{
    public function testNullWriterReturnsNull(): void
    {
        self::assertNull((new NullSecurityReportWriter())->write($this->stats()));
    }

    public function testClaudeWriterExtractsReportText(): void
    {
        $report = 'victim@x.test 계정에 여러 IP 로부터 실패 시도가 집중됐습니다. 크리덴셜 스터핑이 의심됩니다.';
        $response = json_encode([
            'content' => [['type' => 'text', 'text' => $report]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertSame($report, $this->fakeClaude($response)->write($this->stats()));
    }

    public function testClaudeWriterTrimsSurroundingWhitespace(): void
    {
        $response = json_encode([
            'content' => [['type' => 'text', 'text' => "\n  보안 리포트 본문  \n"]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertSame('보안 리포트 본문', $this->fakeClaude($response)->write($this->stats()));
    }

    public function testClaudeWriterReturnsNullOnRequestFailure(): void
    {
        self::assertNull($this->fakeClaude(null)->write($this->stats()));
    }

    public function testClaudeWriterReturnsNullOnMalformedResponse(): void
    {
        self::assertNull($this->fakeClaude('not json at all')->write($this->stats()));
    }

    private function stats(): DailySecurityStats
    {
        return new DailySecurityStats(
            date: '2026-07-12',
            totalAttempts: 40,
            failedAttempts: 35,
            anomalyCount: 3,
            maxScore: 92,
            topAccounts: [['email' => 'victim@x.test', 'failures' => 30, 'attempts' => 33]],
            topIps: [['ip' => '10.0.0.9', 'attempts' => 20]],
        );
    }

    /**
     * request() 를 고정 응답으로 대체한 테스트용 Claude 보안 리포트 작성기.
     */
    private function fakeClaude(?string $response): ClaudeSecurityReportWriter
    {
        return new class ('test-key', 'claude-haiku-4-5-20251001', 5, $response) extends ClaudeSecurityReportWriter {
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
