<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testAppNameIsAitessera(): void
    {
        self::assertSame('AITessera', Version::APP_NAME);
    }

    public function testAppVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::APP_VERSION);
    }
}
