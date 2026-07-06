<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * client_logs 테이블 — 프론트(앱/웹)에서 수집한 로그의 가공 저장소.
 *
 * 원시 로그는 var/logs/raw/ 에 파일로 보존하고, 가공 데이터를 이 테이블에 적재한다.
 * user_id 는 익명 로그도 허용하므로 FK 를 걸지 않는다(느슨한 참조).
 */
final class CreateClientLogsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('client_logs', ['id' => false, 'primary_key' => ['id'], 'comment' => '클라이언트 로그'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('level', 'string', ['limit' => 20, 'comment' => 'debug|info|notice|warning|error|critical'])
            ->addColumn('message', 'text')
            ->addColumn('context', 'json', ['null' => true])
            ->addColumn('source', 'string', ['limit' => 50, 'null' => true, 'comment' => '발생 앱/화면'])
            ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('logged_at', 'datetime', ['null' => true, 'comment' => '클라이언트 발생 시각'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['level'], ['name' => 'idx_client_logs_level'])
            ->addIndex(['source'], ['name' => 'idx_client_logs_source'])
            ->addIndex(['created_at'], ['name' => 'idx_client_logs_created_at'])
            ->create();
    }
}
