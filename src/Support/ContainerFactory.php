<?php

declare(strict_types=1);

namespace App\Support;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/**
 * DI 컨테이너(PHP-DI) 빌더.
 *
 * 프론트 컨트롤러와 테스트가 동일한 방식으로 컨테이너를 구성하도록 한 곳에 모은다.
 */
final class ContainerFactory
{
    public static function build(): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');

        return $builder->build();
    }
}
