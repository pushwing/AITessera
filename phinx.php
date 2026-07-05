<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

// .env 로딩 (없어도 실패하지 않음 — CI/운영은 환경변수 주입 가능)
Dotenv::createImmutable(__DIR__)->safeLoad();

$mysqlEnv = static fn (): array => [
    'adapter' => 'mysql',
    'host'    => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'name'    => $_ENV['DB_NAME'] ?? 'aitessera',
    'user'    => $_ENV['DB_USER'] ?? 'root',
    'pass'    => $_ENV['DB_PASS'] ?? '',
    'port'    => (int) ($_ENV['DB_PORT'] ?? 3306),
    'charset' => 'utf8mb4',
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
        'seeds'      => __DIR__ . '/migrations/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinx_migrations',
        'default_environment'     => $_ENV['APP_ENV'] ?? 'local',
        'local'      => $mysqlEnv(),
        'testing'    => $mysqlEnv(),
        'production' => $mysqlEnv(),
    ],
    'version_order' => 'creation',
];
