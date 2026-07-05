<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * 시스템 시각을 반환하는 기본 시계 구현 (PSR-20).
 *
 * JWT 만료 검증 등에서 현재 시각이 필요할 때 주입받아 사용한다.
 * 테스트에서는 고정 시각을 반환하는 대체 구현으로 교체할 수 있다.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
