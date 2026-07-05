<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * DB 연결 제공 계약 — 지연 연결된 PDO 핸들을 반환한다.
 */
interface ConnectionInterface
{
    public function pdo(): PDO;
}
