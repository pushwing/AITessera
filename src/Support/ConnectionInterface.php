<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * DB 연결 제공 계약 — 지연 연결된 PDO 핸들과 트랜잭션 실행을 제공한다.
 */
interface ConnectionInterface
{
    public function pdo(): PDO;

    /**
     * 콜백을 하나의 트랜잭션으로 실행한다. 예외 발생 시 롤백 후 재던진다.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed;
}
