<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Affiliation;
use App\Domain\Request\LoginRequest;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class LoginRequestTest extends TestCase
{
    public function testValidDataProducesDto(): void
    {
        $request = LoginRequest::fromArray([
            'email' => 'user@aivance.test',
            'password' => 'secret',
            'affiliation' => 'aivance',
        ]);

        self::assertSame('user@aivance.test', $request->email);
        self::assertSame('secret', $request->password);
        self::assertSame(Affiliation::Aivance, $request->affiliation);
    }

    public function testInvalidEmailThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'not-an-email', 'password' => 'secret', 'affiliation' => 'aivance']);
    }

    public function testMissingPasswordThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'affiliation' => 'aivance']);
    }

    public function testEmptyPasswordThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'password' => '', 'affiliation' => 'aivance']);
    }

    public function testMissingAffiliationThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'password' => 'secret']);
    }

    public function testInvalidAffiliationThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'password' => 'secret', 'affiliation' => 'not-a-real-affiliation']);
    }
}
