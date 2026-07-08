<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * users 이메일 유니크 제약을 "활성(미삭제) 회원"으로 스코핑한다 — 이슈 #39(회원 탈퇴).
 *
 * 탈퇴는 소프트 삭제(deleted_at 설정)로 처리하되, 탈퇴한 이메일은 재가입 시 재사용할 수 있어야
 * 한다. 기존 uniq_users_email 은 삭제된 행까지 이메일을 점유해 동일 이메일 재가입을 막았다.
 *
 * 이메일 원본은 감사(audit)를 위해 그대로 보존하고, 대신 생성 컬럼 email_active
 * (활성 행이면 email, 삭제 행이면 NULL)에 유니크를 건다. MySQL 유니크 인덱스는 다중 NULL 을
 * 허용하므로, 삭제된 여러 행이 같은 이메일이어도 충돌하지 않고 활성 행끼리만 이메일이 유일해진다.
 *
 * 생성 컬럼은 INSERT/SELECT 대상 컬럼 목록에 등장하지 않으므로 기존 쿼리는 영향받지 않는다.
 */
final class ScopeUsersEmailUniqueToActive extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE users DROP INDEX uniq_users_email');
        $this->execute(
            'ALTER TABLE users
                ADD COLUMN email_active VARCHAR(255)
                GENERATED ALWAYS AS (IF(deleted_at IS NULL, email, NULL)) STORED
                COMMENT \'활성 회원 이메일(유니크 스코핑용) — 삭제 시 NULL\'',
        );
        $this->execute('ALTER TABLE users ADD UNIQUE INDEX uniq_users_email_active (email_active)');
    }

    /**
     * ⚠️ 사실상 단방향(one-way) 마이그레이션이다.
     *
     * 이 기능은 "탈퇴한 이메일 재사용"을 허용하므로, up() 배포 이후 탈퇴 회원과 동일 이메일의
     * 재가입 회원이 공존할 수 있다. 그 상태에서 롤백하면 email 컬럼에 중복값이 남아
     * 마지막의 `ADD UNIQUE INDEX uniq_users_email (email)` 이 Duplicate entry 로 실패한다.
     * 즉 재가입이 한 번이라도 일어난 뒤에는 이 down() 이 깨진다(운영에서 롤백 불가로 간주).
     *
     * 스키마 형상만 되돌리는 용도(개발/테스트, 재사용 발생 전)로만 안전하다.
     */
    public function down(): void
    {
        $this->execute('ALTER TABLE users DROP INDEX uniq_users_email_active');
        $this->execute('ALTER TABLE users DROP COLUMN email_active');
        $this->execute('ALTER TABLE users ADD UNIQUE INDEX uniq_users_email (email)');
    }
}
