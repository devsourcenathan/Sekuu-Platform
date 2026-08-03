<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use App\Platform\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Traduit toute exception en réponse d'erreur normalisée.
 *
 * Aucun module ne doit formater d'erreur lui-même : il lève une exception,
 * ce renderer produit le corps conforme au catalogue.
 *
 * @see docs/02-standards/error-codes.md
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $this->expectsJson($request)) {
            return null;
        }

        return match (true) {
            $e instanceof DomainException => ApiResponse::error(
                $e->errorCode,
                $e->getMessage(),
                $e->status,
                $e->details,
            ),

            $e instanceof ValidationException => ApiResponse::error(
                'VALIDATION_ERROR',
                __('Validation failed.'),
                422,
                $e->errors(),
            ),

            $e instanceof AuthenticationException => ApiResponse::error(
                'UNAUTHENTICATED',
                __('Authentication is required.'),
                401,
            ),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponse::error(
                'FORBIDDEN',
                __('Access denied.'),
                403,
            ),

            // Une ressource appartenant à une autre organisation doit être
            // indiscernable d'une ressource inexistante.
            $e instanceof ModelNotFoundException => ApiResponse::error(
                'RESOURCE_NOT_FOUND',
                __('The requested resource does not exist.'),
                404,
            ),

            $e instanceof NotFoundHttpException => ApiResponse::error(
                'ENDPOINT_NOT_FOUND',
                __('The requested endpoint does not exist.'),
                404,
            ),

            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                'BAD_REQUEST',
                __('The HTTP method is not allowed for this endpoint.'),
                405,
            ),

            $e instanceof TooManyRequestsHttpException => $this->rateLimited($e),

            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $this->codeForStatus($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : __('Request failed.'),
                $e->getStatusCode(),
            ),

            default => $this->internal($e),
        };
    }

    private function rateLimited(TooManyRequestsHttpException $e): JsonResponse
    {
        $response = ApiResponse::error(
            'RATE_LIMIT_EXCEEDED',
            __('Too many requests.'),
            429,
        );

        foreach ($e->getHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * Une erreur interne n'expose jamais de trace, de requête SQL ni de nom de
     * classe en production : le request_id suffit au support pour retrouver le log.
     */
    private function internal(Throwable $e): JsonResponse
    {
        return ApiResponse::error(
            'INTERNAL_ERROR',
            config('app.debug')
                ? $e->getMessage()
                : __('An unexpected error occurred.'),
            500,
        );
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'RESOURCE_NOT_FOUND',
            409 => 'RESOURCE_CONFLICT',
            410 => 'RESOURCE_GONE',
            422 => 'UNPROCESSABLE_STATE',
            429 => 'RATE_LIMIT_EXCEEDED',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'INTERNAL_ERROR',
        };
    }

    private function expectsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
