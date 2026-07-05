<?php

declare(strict_types=1);

namespace App\Support;

use OpenApi\Generator;
use RuntimeException;

/**
 * OpenAPI 스펙 생성기 — src/ 의 swagger-php 어트리뷰트를 스캔해 JSON 으로 변환한다.
 *
 * 운영 환경에서는 매 요청 재스캔을 피하려고 결과를 `var/cache/openapi.json` 에 캐시한다.
 * 개발 환경에서는 항상 재생성해 코드 변경이 즉시 반영되게 한다.
 */
final class OpenApiGenerator
{
    private readonly string $srcPath;
    private readonly string $cacheFile;

    public function __construct(private readonly Config $config)
    {
        $root = dirname(__DIR__, 2);
        $this->srcPath = $root . '/src';
        $this->cacheFile = $root . '/var/cache/openapi.json';
    }

    public function toJson(): string
    {
        if ($this->config->isProduction()) {
            $cached = is_file($this->cacheFile) ? file_get_contents($this->cacheFile) : false;
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $openapi = Generator::scan([$this->srcPath]);
        if ($openapi === null) {
            throw new RuntimeException('OpenAPI 스펙 생성에 실패했습니다.');
        }
        $json = $openapi->toJson();

        if ($this->config->isProduction()) {
            $dir = dirname($this->cacheFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0o775, true);
            }
            file_put_contents($this->cacheFile, $json);
        }

        return $json;
    }
}
