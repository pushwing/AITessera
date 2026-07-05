<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * PDO 지연 연결 래퍼.
 *
 * 컨테이너가 이 객체를 주입해도 실제 DB 연결은 첫 `pdo()` 호출 시점까지 미룬다.
 * 따라서 검증·인증 단계에서 먼저 실패하는 요청은 DB 를 연결하지 않는다.
 */
final class Database implements ConnectionInterface
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    private function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->dbHost,
            $this->config->dbPort,
            $this->config->dbName,
        );

        return new PDO($dsn, $this->config->dbUser, $this->config->dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
