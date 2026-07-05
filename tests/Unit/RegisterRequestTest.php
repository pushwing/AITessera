<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Affiliation;
use App\Domain\Request\RegisterRequest;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class RegisterRequestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validData(): array
    {
        return [
            'email' => 'user@aivance.test',
            'password' => 'password1234!',
            'affiliation' => 'aivance',
            'name' => '홍길동',
            'contact' => '010-1234-5678',
            'terms_agreed' => true,
            'third_party_agreed' => true,
        ];
    }

    public function testValidDataProducesDto(): void
    {
        $request = RegisterRequest::fromArray(self::validData() + ['company' => 'AIvance', 'profile' => ['team' => 'ops']]);

        self::assertSame('user@aivance.test', $request->email);
        self::assertSame(Affiliation::Aivance, $request->affiliation);
        self::assertSame('AIvance', $request->company);
        self::assertSame(['team' => 'ops'], $request->profile);
    }

    public function testCompanyAndProfileAreOptional(): void
    {
        $request = RegisterRequest::fromArray(self::validData());

        self::assertNull($request->company);
        self::assertSame([], $request->profile);
    }

    public function testWeakPasswordThrows(): void
    {
        $this->expectException(ValidationException::class);
        RegisterRequest::fromArray(['password' => 'short1!'] + self::validData());
    }

    public function testUnknownAffiliationThrows(): void
    {
        $this->expectException(ValidationException::class);
        RegisterRequest::fromArray(['affiliation' => 'unknown'] + self::validData());
    }

    public function testMissingTermsAgreementThrows(): void
    {
        $data = self::validData();
        unset($data['terms_agreed']);

        $this->expectException(ValidationException::class);
        RegisterRequest::fromArray($data);
    }

    public function testUnacceptedThirdPartyAgreementThrows(): void
    {
        $this->expectException(ValidationException::class);
        RegisterRequest::fromArray(['third_party_agreed' => false] + self::validData());
    }
}
