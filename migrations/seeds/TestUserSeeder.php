<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * 개발·수동 테스트용 사용자 시더 (idempotent).
 *
 * 이메일 인증이 완료된 활성 계정을 하나 생성한다. 운영 배포 시 자동 실행되지 않으며,
 * 필요할 때만 `php bin/console seed:run` 으로 수동 실행한다.
 *
 *   이메일: admin@aivance.test / 비밀번호: password1234!
 */
final class TestUserSeeder extends AbstractSeed
{
    public function run(): void
    {
        $exists = $this->fetchRow(
            "SELECT id FROM users WHERE email = 'admin@aivance.test' LIMIT 1",
        );
        if ($exists !== false) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->table('users')->insert([
            [
                'email' => 'admin@aivance.test',
                'password_hash' => password_hash('password1234!', PASSWORD_ARGON2ID),
                'affiliation' => 'aivance',
                'name' => '관리자',
                'contact' => '010-0000-0000',
                'company' => 'AIvance',
                'email_verified_at' => $now,
                'terms_agreed_at' => $now,
                'third_party_agreed_at' => $now,
                'is_active' => 1,
                'created_at' => $now,
            ],
        ])->save();
    }
}
