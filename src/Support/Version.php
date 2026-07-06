<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 애플리케이션 기본 메타 정보 홀더.
 *
 * 버전의 단일 출처(single source of truth). `/api/v1/health` 응답과 OpenAPI 문서
 * (OpenApiSpec 의 Info.version)가 모두 이 상수를 참조하므로, 버전은 여기서만 올린다.
 */
final class Version
{
    public const string APP_NAME = 'AITessera';

    public const string APP_VERSION = '1.0.0';
}
