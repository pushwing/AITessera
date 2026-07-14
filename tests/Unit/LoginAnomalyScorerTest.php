<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Security\LoginEventSignals;
use App\Support\Ai\ClaudeLoginAnomalyScorer;
use App\Support\Ai\LoginAnomalyScorerInterface;
use App\Support\Ai\RuleLoginAnomalyScorer;
use PHPUnit\Framework\TestCase;

final class LoginAnomalyScorerTest extends TestCase
{
    public function testRuleScorerReturnsZeroForNormalActivity(): void
    {
        $result = (new RuleLoginAnomalyScorer())->score(true, new LoginEventSignals(1, 0, 1));

        self::assertSame(0, $result->score);
        self::assertSame('정상', $result->reason);
    }

    public function testRuleScorerFlagsDistributedAttempts(): void
    {
        // 동일 계정에 4개 IP 시도 → 분산 공격 신호
        $result = (new RuleLoginAnomalyScorer())->score(false, new LoginEventSignals(4, 2, 6));

        self::assertGreaterThanOrEqual(40, $result->score);
        self::assertStringContainsString('IP', $result->reason);
    }

    public function testRuleScorerFlagsCredentialStuffingSuccess(): void
    {
        // 실패 급증(5회) 후 성공 → 크리덴셜 스터핑 성공 징후, 강한 점수
        $result = (new RuleLoginAnomalyScorer())->score(true, new LoginEventSignals(1, 5, 6));

        self::assertGreaterThanOrEqual(70, $result->score);
        self::assertStringContainsString('스터핑', $result->reason);
    }

    public function testRuleScorerCapsAtHundred(): void
    {
        // 모든 신호가 겹쳐도 100 을 넘지 않는다
        $result = (new RuleLoginAnomalyScorer())->score(true, new LoginEventSignals(9, 20, 30));

        self::assertSame(100, $result->score);
    }

    public function testClaudeScorerParsesResponse(): void
    {
        $response = $this->claudeText('{"score": 85, "reason": "이상 활동 감지"}');
        $result = $this->fakeClaude($response)->score(false, new LoginEventSignals(4, 3, 7));

        self::assertSame(85, $result->score);
        self::assertSame('이상 활동 감지', $result->reason);
    }

    public function testClaudeScorerClampsScoreToRange(): void
    {
        $result = $this->fakeClaude($this->claudeText('{"score": 250, "reason": "x"}'))
            ->score(false, new LoginEventSignals(4, 3, 7));

        self::assertSame(100, $result->score);
    }

    public function testClaudeScorerFallsBackToRuleOnRequestFailure(): void
    {
        // request() 가 null(타임아웃 등) → 규칙 기반 폴백 점수를 사용해야 한다
        $signals = new LoginEventSignals(1, 5, 6);
        $expected = (new RuleLoginAnomalyScorer())->score(true, $signals);

        $result = $this->fakeClaude(null)->score(true, $signals);

        self::assertSame($expected->score, $result->score);
        self::assertSame($expected->reason, $result->reason);
    }

    public function testClaudeScorerFallsBackOnMalformedResponse(): void
    {
        $signals = new LoginEventSignals(4, 0, 4);
        $expected = (new RuleLoginAnomalyScorer())->score(false, $signals);

        $result = $this->fakeClaude($this->claudeText('완전히 깨진 응답'))->score(false, $signals);

        self::assertSame($expected->score, $result->score);
    }

    private function claudeText(string $text): string
    {
        return (string) json_encode([
            'content' => [['type' => 'text', 'text' => $text]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function fakeClaude(?string $response): ClaudeLoginAnomalyScorer
    {
        return new class ('test-key', 'claude-haiku-4-5-20251001', new RuleLoginAnomalyScorer(), $response) extends ClaudeLoginAnomalyScorer {
            public function __construct(
                string $apiKey,
                string $model,
                LoginAnomalyScorerInterface $fallback,
                private readonly ?string $cannedResponse,
            ) {
                parent::__construct($apiKey, $model, $fallback);
            }

            protected function request(string $body): ?string
            {
                return $this->cannedResponse;
            }
        };
    }
}
