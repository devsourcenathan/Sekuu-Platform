<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $database = $this->databaseIsReachable();

        return ApiResponse::success([
            'service' => 'identity',
            'version' => 'v1',
            'status' => $database ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database ? 'ok' : 'unreachable',
            ],
        ], status: $database ? 200 : 503);
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
