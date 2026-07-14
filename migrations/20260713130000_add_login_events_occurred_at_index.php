<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * login_events.occurred_at 단독 인덱스 추가 — 일일 보안 리포트의 날짜 범위 집계용.
 *
 * 기존 인덱스(idx_login_events_email_occurred·idx_login_events_ip_occurred)는 occurred_at 이
 * 후행 컬럼이라, 계정·IP 조건 없이 occurred_at 범위만으로 하루치를 훑는 리포트 집계
 * (GenerateSecurityReport)에는 활용되지 못한다. 단독 인덱스로 풀스캔을 막는다.
 */
final class AddLoginEventsOccurredAtIndex extends AbstractMigration
{
    public function change(): void
    {
        $this->table('login_events')
            ->addIndex(['occurred_at'], ['name' => 'idx_login_events_occurred_at'])
            ->update();
    }
}
