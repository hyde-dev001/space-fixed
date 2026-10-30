<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AttachPrivilegedCorrelationId
{
    public const ATTRIBUTE = 'privileged_audit_correlation_id';

    public const HEADER = 'X-Correlation-ID';

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
