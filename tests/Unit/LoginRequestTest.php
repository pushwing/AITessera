<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Request\LoginRequest;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class LoginRequestTest extends TestCase
{
    public function testValidDataProducesDto(): void
    {
        $request = LoginRequest::fromArray(['email' => 'user@aivance.test', 'password' => 'secret']);

        self::assertSame('user@aivance.test', $request->email);
        self::assertSame('secret', $request->password);
    }

    public function testInvalidEmailThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'not-an-email', 'password' => 'secret']);
    }

    public function testMissingPasswordThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test']);
    }

    public function testEmptyPasswordThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'password' => '']);
    }
}
