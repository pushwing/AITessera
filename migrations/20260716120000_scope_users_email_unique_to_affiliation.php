<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * users 이메일 유니크 제약을 (email_active, affiliation) 복합으로 확장한다 — 이슈: 동일 이메일 다중 소속 가입.
 *
 * 기존 uniq_users_email_active(email_active)는 소속과 무관하게 이메일 전역 유일을 강제해,
 * 같은 사람이 서로 다른 제품군(affiliation)에 각각 가입하는 것을 막았다. affiliation을 인덱스에
 * 포함시켜 "이메일 유일성"의 스코프를 소속 단위로 좁힌다. email_active는 탈퇴 시 NULL이 되는
 * 기존 생성 컬럼을 그대로 재사용하므로 탈퇴 후 재가입 허용 동작은 변하지 않는다.
 *
 * 기존 데이터는 이미 (소속당 이메일 유일)을 만족하므로 무중단 적용 가능(백필 불필요).
 */
final class ScopeUsersEmailUniqueToAffiliation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE users DROP INDEX uniq_users_email_active');
        $this->execute(
            'ALTER TABLE users ADD UNIQUE INDEX uniq_users_email_active_affiliation (email_active, affiliation)',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE users DROP INDEX uniq_users_email_active_affiliation');
        $this->execute('ALTER TABLE users ADD UNIQUE INDEX uniq_users_email_active (email_active)');
    }
}
