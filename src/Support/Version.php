<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 애플리케이션 기본 메타 정보 홀더.
 *
 * 스캐폴딩 단계에서 PSR-4 오토로딩·정적분석·테스트 대상을 앵커링하기 위한 최소 클래스.
 */
final class Version
{
    public const string APP_NAME = 'AITessera';

    public const string APP_VERSION = '0.1.0';
}
