<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Request\LogRequest;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class LogRequestTest extends TestCase
{
    public function testMinimalLogIsValid(): void
    {
        $request = LogRequest::fromArray(['level' => 'info', 'message' => '안녕']);

        self::assertSame('info', $request->level);
        self::assertSame('안녕', $request->message);
        self::assertSame([], $request->context);
        self::assertNull($request->source);
        self::assertNull($request->userId);
        self::assertNull($request->loggedAt);
    }

    public function testFullLogMapsAllFields(): void
    {
        $request = LogRequest::fromArray([
            'level' => 'error',
            'message' => 'boom',
            'context' => ['code' => 500],
            'source' => 'web-checkout',
            'user_id' => 42,
            'logged_at' => '2026-07-05 10:00:00',
        ]);

        self::assertSame(['code' => 500], $request->context);
        self::assertSame('web-checkout', $request->source);
        self::assertSame(42, $request->userId);
        self::assertSame('2026-07-05 10:00:00', $request->loggedAt);
        self::assertSame('boom', $request->toPayload()['message']);
    }

    public function testUnknownLevelThrows(): void
    {
        $this->expectException(ValidationException::class);
        LogRequest::fromArray(['level' => 'fatal', 'message' => 'x']);
    }

    public function testEmptyMessageThrows(): void
    {
        $this->expectException(ValidationException::class);
        LogRequest::fromArray(['level' => 'info', 'message' => '']);
    }

    public function testMissingLevelThrows(): void
    {
        $this->expectException(ValidationException::class);
        LogRequest::fromArray(['message' => 'x']);
    }
}
