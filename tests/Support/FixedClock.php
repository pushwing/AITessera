<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * 고정 시각을 반환하는 테스트용 시계.
 */
final readonly class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
