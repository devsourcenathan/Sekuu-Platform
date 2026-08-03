<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Infrastructure\Providers\ProviderRegistry;

final class HealthController
{
    public function __invoke(ProviderRegistry $registry): JsonResponse
    {
        $channels = [];

        foreach (Channel::all() as $channel) {
            $channels[$channel] = $registry->hasChannel($channel) ? 'configured' : 'unconfigured';
        }

        return ApiResponse::success([
            'service' => 'notify',
            'version' => 'v1',
            'status' => 'ok',
            'channels' => $channels,
        ]);
    }
}
