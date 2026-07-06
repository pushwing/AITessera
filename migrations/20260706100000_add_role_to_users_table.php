<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * users 테이블에 회원구분(role) 컬럼 추가 — 이슈 #29.
 *
 * 1=운영자, 2=대행사, 3=일반회원. 기본값 3(일반회원)으로 생성하므로 ALTER 시점에 기존 행은
 * 모두 일반회원으로 자동 백필된다(별도 UPDATE 불필요). 소속(affiliation)과 함께 운영자 페이지
 * 접근 판단에 쓰인다.
 */
final class AddRoleToUsersTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('role', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'signed' => false,
                'default' => 3,
                'null' => false,
                'after' => 'affiliation',
                'comment' => '회원구분 1=운영자 2=대행사 3=일반회원',
            ])
            ->addIndex(['role'], ['name' => 'idx_users_role'])
            ->update();
    }
}
