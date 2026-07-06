<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * 사용자 소속 서비스 — AIvance 제품군.
 */
enum Affiliation: string
{
    case Aicura = 'aicura';
    case Aicopia = 'aicopia';
    case Aicreo = 'aicreo';
    case Aivance = 'aivance';
    case Ailicet = 'ailicet';
}
