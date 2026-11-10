<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** What a notice of a gateway must be before anything reads it: a JSON body of at most 64 KB. Anything else is refused as a bad request. */
final readonly class GuardNoticeBody
{
    public const int MAX_BYTES = 65536;

    public function handle(Request $request, Closure $next): Response
    {
        $declared = (int) $request->headers->get('Content-Length', '0');

        if (! $request->isJson() || $declared > self::MAX_BYTES || strlen($request->getContent()) > self::MAX_BYTES) {
            throw new HttpException(400);
        }

        return $next($request);
    }
}
