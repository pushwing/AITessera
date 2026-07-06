<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * PSR-7 응답을 실제 HTTP 출력(상태줄·헤더·본문)으로 방출한다.
 *
 * 프론트 컨트롤러에서 파이프라인 결과를 클라이언트로 내보낼 때 사용한다.
 */
final class ResponseEmitter
{
    public function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            header(sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            ), true, $response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        echo $response->getBody();
    }
}
