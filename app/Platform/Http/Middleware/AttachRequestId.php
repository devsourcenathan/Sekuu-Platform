<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Http\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attribue un request_id à chaque requête, le propage dans les logs
 * et le renvoie dans le header de réponse.
 */
final class AttachRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestId::reset();

        $incoming = (string) $request->header(RequestId::HEADER, '');

        RequestId::set(
            RequestId::isAcceptable($incoming) ? $incoming : RequestId::generate()
        );

        $requestId = RequestId::current();

        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $requestId);

        return $response;
    }
}
