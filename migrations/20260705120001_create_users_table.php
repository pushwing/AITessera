<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * users 테이블 — 인증 주체.
 *
 * 이슈 #1 구조 반영:
 *  - 이메일(로그인 ID)·비밀번호·소속(5개 서비스)
 *  - 가입 시 이메일 인증 (email_verified_at)
 *  - 약관동의(terms_agreed_at)·제3자 정보제공동의(third_party_agreed_at)
 *  - 이름·연락처 필수, 회사 선택
 *  - 소속별 부가 항목은 profile(JSON) 한 컬럼에 보관
 */
final class CreateUsersTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users', ['signed' => false, 'comment' => '인증 사용자'])
            ->addColumn('email', 'string', ['limit' => 255, 'comment' => '로그인 ID'])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'comment' => 'Argon2id 해시'])
            ->addColumn('affiliation', 'string', ['limit' => 20, 'comment' => 'aicura|aicopia|aicreo|aivance|ailicet'])
            ->addColumn('name', 'string', ['limit' => 100, 'comment' => '이름 (필수)'])
            ->addColumn('contact', 'string', ['limit' => 50, 'comment' => '연락처 (필수)'])
            ->addColumn('company', 'string', ['limit' => 255, 'null' => true, 'comment' => '회사 (선택)'])
            ->addColumn('profile', 'json', ['null' => true, 'comment' => '소속별 부가 항목'])
            ->addColumn('email_verified_at', 'datetime', ['null' => true, 'comment' => '이메일 인증 완료 시각'])
            ->addColumn('terms_agreed_at', 'datetime', ['null' => true, 'comment' => '약관 동의 시각'])
            ->addColumn('third_party_agreed_at', 'datetime', ['null' => true, 'comment' => '제3자 정보제공 동의 시각'])
            ->addColumn('is_active', 'boolean', ['default' => true, 'comment' => '활성 여부'])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('deleted_at', 'datetime', ['null' => true, 'comment' => '소프트 삭제'])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uniq_users_email'])
            ->addIndex(['affiliation'], ['name' => 'idx_users_affiliation'])
            ->create();
    }
}
