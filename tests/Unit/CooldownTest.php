<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cooldown\InMemoryCooldown;
use PHPUnit\Framework\TestCase;

final class CooldownTest extends TestCase
{
    public function testFirstAcquireSucceedsAndRepeatIsSuppressed(): void
    {
        $cooldown = new InMemoryCooldown();

        self::assertTrue($cooldown->acquire('k', 1800), '최초 획득은 성공해야 한다');
        self::assertFalse($cooldown->acquire('k', 1800), '쿨다운 중 재획득은 실패해야 한다');
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $cooldown = new InMemoryCooldown();

        self::assertTrue($cooldown->acquire('a', 1800));
        self::assertTrue($cooldown->acquire('b', 1800), '다른 키는 서로 영향을 주지 않아야 한다');
        self::assertFalse($cooldown->acquire('a', 1800));
    }
}
