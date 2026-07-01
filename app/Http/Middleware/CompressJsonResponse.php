<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gzip-compress JSON API responses when the client accepts gzip.
 * Skips small payloads and already-encoded responses.
 */
class CompressJsonResponse
{
    private const MIN_BYTES = 1024;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        $accept = (string) $request->header('Accept-Encoding', '');
        if (! str_contains($accept, 'gzip')) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (
            $contentType !== ''
            && ! str_contains($contentType, 'json')
            && ! str_contains($contentType, 'text/')
        ) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($content, 6);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip', true);
        $response->headers->set('Vary', 'Accept-Encoding', false);
        $response->headers->remove('Content-Length');

        return $response;
    }
}
