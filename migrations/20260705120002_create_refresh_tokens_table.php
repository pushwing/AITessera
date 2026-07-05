<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * refresh_tokens 테이블 — Refresh 토큰 회전(rotation)·무효화(revocation) 관리.
 *
 * 토큰 평문은 저장하지 않고 SHA-256 해시만 보관한다(유출 대비).
 * 회전 시 이전 토큰에 revoked_at 을 기록하고, 폐기된 토큰 재사용이 감지되면
 * 해당 사용자의 모든 토큰을 무효화한다.
 */
final class CreateRefreshTokensTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('refresh_tokens', ['id' => false, 'primary_key' => ['id'], 'comment' => 'Refresh 토큰'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'comment' => 'SHA-256(hex)'])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('revoked_at', 'datetime', ['null' => true, 'comment' => '무효화 시각'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_refresh_tokens_token_hash'])
            ->addIndex(['user_id'], ['name' => 'idx_refresh_tokens_user_id'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
