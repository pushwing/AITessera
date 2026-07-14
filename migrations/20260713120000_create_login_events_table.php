<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * login_events 테이블 — 로그인 이상 탐지용 인증 이벤트 저장소.
 *
 * AuthService 가 성공/실패 로그인 시도를 큐로 흘리고, ProcessLoginAnomaly 워커가 비동기로
 * 저장·스코어링한다. 비밀번호·토큰 등 민감정보는 저장하지 않는다(email·ip·user_agent·성공여부).
 * user_id 가 아니라 email 로 기록하는 이유: 존재하지 않는 계정에 대한 시도도 이상 신호이므로.
 */
final class CreateLoginEventsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('login_events', ['id' => false, 'primary_key' => ['id'], 'comment' => '로그인 이상 탐지 이벤트'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('email', 'string', ['limit' => 255, 'comment' => '로그인 시도 대상 이메일'])
            ->addColumn('ip', 'string', ['limit' => 45, 'comment' => 'REMOTE_ADDR (IPv6 대응)'])
            ->addColumn('user_agent', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('success', 'boolean', ['default' => false, 'comment' => '토큰 발급 성공 여부'])
            ->addColumn('anomaly_score', 'integer', ['null' => true, 'comment' => '이상 점수 0~100 (워커 산출)'])
            ->addColumn('anomaly_reason', 'string', ['limit' => 255, 'null' => true, 'comment' => '점수 근거'])
            ->addColumn('occurred_at', 'datetime', ['comment' => '시도 발생 시각'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email', 'occurred_at'], ['name' => 'idx_login_events_email_occurred'])
            ->addIndex(['ip', 'occurred_at'], ['name' => 'idx_login_events_ip_occurred'])
            ->addIndex(['anomaly_score'], ['name' => 'idx_login_events_anomaly_score'])
            ->create();
    }
}
