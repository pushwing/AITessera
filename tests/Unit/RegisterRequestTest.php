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
        $request = RegisterRequest::fromArray(self::validData() + ['company' => 'AIvance']);

        self::assertSame('user@aivance.test', $request->email);
        self::assertSame(Affiliation::Aivance, $request->affiliation);
        self::assertSame('AIvance', $request->company);
    }

    public function testCompanyAndProfileAreOptional(): void
    {
        $request = RegisterRequest::fromArray(self::validData());

        self::assertNull($request->company);
        self::assertSame([], $request->profile);
    }

    public function testValidAffiliationProfilePasses(): void
    {
        $request = RegisterRequest::fromArray(
            ['affiliation' => 'aicura', 'profile' => ['age' => 30, 'sex' => 'M', 'where_from' => 'Seoul']] + self::validData(),
        );

        self::assertSame(['age' => 30, 'sex' => 'M', 'where_from' => 'Seoul'], $request->profile);
    }

    public function testInvalidProfileFieldThrows(): void
    {
        $this->expectException(ValidationException::class);
        RegisterRequest::fromArray(
            ['affiliation' => 'aicopia', 'profile' => ['birthday' => 'not-a-date']] + self::validData(),
        );
    }

    public function testProfileFieldForFieldlessAffiliationThrows(): void
    {
        // 기본 validData 의 소속은 aivance(추가필드 없음) → 어떤 profile 키도 거절.
        $this->expectException(ValidationException::class);
        RegisterRequest::fromArray(['profile' => ['age' => 1]] + self::validData());
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
