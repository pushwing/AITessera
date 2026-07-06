<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * email_verifications 테이블 — 가입 시 발급하는 이메일 인증 토큰.
 *
 * 토큰 평문은 저장하지 않고 SHA-256 해시만 보관한다. 인증 완료 시 consumed_at 을 기록해
 * 재사용을 막고, expires_at 으로 만료를 관리한다.
 */
final class CreateEmailVerificationsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('email_verifications', ['id' => false, 'primary_key' => ['id'], 'comment' => '이메일 인증 토큰'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'comment' => 'SHA-256(hex)'])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('consumed_at', 'datetime', ['null' => true, 'comment' => '인증 완료 시각'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_email_verifications_token_hash'])
            ->addIndex(['user_id'], ['name' => 'idx_email_verifications_user_id'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
