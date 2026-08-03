<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Audit;

use App\Platform\Http\RequestId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Identity\Domain\Models\AuditLog;
use Modules\Identity\Domain\Models\User;

/**
 * Écrit les entrées du journal d'audit.
 *
 * @see docs/02-standards/security.md
 */
final class AuditLogger
{
    /**
     * Clés interdites dans un payload. Un journal ne doit jamais devenir le
     * point de fuite de ce que le reste du système protège.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'password', 'password_hash', 'password_confirmation',
        'token', 'token_hash', 'access_token', 'refresh_token',
        'secret', 'api_key', 'authorization', 'private_key',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $action,
        ?User $user = null,
        ?string $organizationId = null,
        ?Model $target = null,
        array $payload = [],
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $user?->id,
            'organization_id' => $organizationId,
            'action' => $action,
            'target_type' => $target !== null ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'request_id' => RequestId::current(),
            'payload' => self::scrub($payload),
        ]);
    }

    /**
     * Retire récursivement toute valeur sensible, quelle que soit sa
     * profondeur dans le tableau.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function scrub(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (self::isForbidden((string) $key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $clean;
    }

    private static function isForbidden(string $key): bool
    {
        $normalised = str_replace('-', '_', mb_strtolower($key));

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if (str_contains($normalised, $forbidden)) {
                return true;
            }
        }

        return false;
    }
}
