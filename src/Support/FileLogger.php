<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 최소 파일 로거 — 구조화(JSON) 로그를 `var/logs/app-YYYY-MM-DD.log` 에 append 한다.
 *
 * 부트스트랩 단계의 경량 구현. 이후 PSR-3 로거(monolog 등)로 교체할 수 있다.
 */
final class FileLogger
{
    private readonly string $logDir;

    public function __construct(?string $logDir = null)
    {
        // src/Support → 프로젝트 루트 기준 var/logs
        $this->logDir = $logDir ?? dirname(__DIR__, 2) . '/var/logs';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0o775, true);
        }

        $line = json_encode([
            'time' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents(
            $this->logDir . '/app-' . date('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
