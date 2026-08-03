<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\JsonResponse;

/**
 * Enveloppe de réponse commune à toutes les API de la plateforme.
 *
 * @see docs/02-standards/api-guidelines.md
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(mixed $data = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $data,
            'meta' => self::meta($meta),
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function created(mixed $data = null, ?array $meta = null): JsonResponse
    {
        return self::success($data, $meta, 201);
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, mixed>|null  $meta
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        ?array $details = null,
        ?array $meta = null,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return new JsonResponse([
            'success' => false,
            'error' => $error,
            'meta' => self::meta($meta),
        ], $status);
    }

    /**
     * Le request_id est présent dans chaque réponse, y compris en erreur.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private static function meta(?array $meta): array
    {
        return array_merge($meta ?? [], [
            'request_id' => RequestId::current(),
        ]);
    }
}
