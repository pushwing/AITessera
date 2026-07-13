<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * client_logs 테이블에 AI 분류 결과 컬럼 추가 — 이슈 #50.
 *
 * error/critical 로그를 Claude API 로 분류·요약한 결과를 저장한다. AI 호출이 없거나
 * 실패한 로그는 두 컬럼 모두 NULL 로 남는다(graceful degradation).
 * - ai_category: 근본원인 카테고리(network|auth|ui-render|payload 등)
 * - ai_summary : 한국어 한 줄 요약
 */
final class AddAiColumnsToClientLogsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('client_logs')
            ->addColumn('ai_category', 'string', [
                'limit' => 50,
                'null' => true,
                'after' => 'context',
                'comment' => 'AI 근본원인 카테고리',
            ])
            ->addColumn('ai_summary', 'text', [
                'null' => true,
                'after' => 'ai_category',
                'comment' => 'AI 한 줄 요약(한국어)',
            ])
            ->addIndex(['ai_category'], ['name' => 'idx_client_logs_ai_category'])
            ->update();
    }
}
