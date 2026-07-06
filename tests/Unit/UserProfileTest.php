<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Affiliation;
use App\Domain\UserProfile;
use PHPUnit\Framework\TestCase;

final class UserProfileTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'email' => 'admin@aivance.test',
            'name' => '관리자',
            'affiliation' => 'aivance',
            'role' => 3,
            'contact' => '010-0000-0000',
            'company' => 'AIvance',
            'profile' => null,
            'email_verified_at' => '2026-07-01 00:00:00',
            'created_at' => '2026-07-01 09:00:00',
        ], $overrides);
    }

    public function testToArrayExposesSafeFieldsOnly(): void
    {
        $data = UserProfile::fromRow(self::row())->toArray();

        self::assertSame(1, $data['id']);
        self::assertSame('admin@aivance.test', $data['email']);
        self::assertSame('aivance', $data['affiliation']);
        self::assertSame(3, $data['role']);
        self::assertTrue($data['email_verified']);
        self::assertNull($data['profile']);
        self::assertArrayNotHasKey('password_hash', $data);
    }

    public function testProfileJsonIsDecoded(): void
    {
        $profile = UserProfile::fromRow(self::row(['profile' => '{"age":30,"sex":"M"}']));

        self::assertSame(['age' => 30, 'sex' => 'M'], $profile->profile);
        self::assertSame(['age' => 30, 'sex' => 'M'], $profile->toArray()['profile']);
    }

    public function testUnverifiedEmailMapsToFalse(): void
    {
        $data = UserProfile::fromRow(self::row(['email_verified_at' => null]))->toArray();

        self::assertFalse($data['email_verified']);
    }

    public function testAffiliationMapsToEnum(): void
    {
        self::assertSame(Affiliation::Aicura, UserProfile::fromRow(self::row(['affiliation' => 'aicura']))->affiliation);
    }
}
