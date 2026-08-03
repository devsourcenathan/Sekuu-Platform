<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class DeviceInfo
{
    public function __construct(
        public ?string $deviceName = null,
        public ?string $platform = null,
        public ?string $browser = null,
        public ?string $ipAddress = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $agent = (string) $request->userAgent();

        return new self(
            deviceName: $agent !== '' ? Str::limit($agent, 200, '') : null,
            platform: self::detect($agent, ['Windows', 'Macintosh', 'Linux', 'Android', 'iPhone', 'iPad']),
            browser: self::detect($agent, ['Firefox', 'Edg', 'Chrome', 'Safari']),
            ipAddress: $request->ip(),
        );
    }

    /**
     * @param  list<string>  $candidates
     */
    private static function detect(string $agent, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (str_contains($agent, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
