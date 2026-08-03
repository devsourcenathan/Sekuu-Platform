<?php

declare(strict_types=1);

namespace Modules\Identity\Application\ApiKeys;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\ApiKey;
use Modules\Identity\Domain\Models\User;

final class IssueApiKey
{
    /**
     * Scopes qu'une clé peut porter. Une clé ne peut jamais obtenir un droit
     * qui n'existe pas ici : la liste est fermée.
     *
     * @var list<string>
     */
    public const SCOPES = [
        'notifications.send',
        'notifications.read',
        'notifications.manage',
    ];

    /**
     * @param  list<string>  $scopes
     */
    public function handle(
        string $organizationId,
        string $name,
        array $scopes,
        User $creator,
        ?string $expiresAt = null,
    ): IssuedApiKey {
        $unknown = array_values(array_diff($scopes, self::SCOPES));

        if ($unknown !== []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_unknown_scope', ['scopes' => implode(', ', $unknown)]),
            );
        }

        // `sk_live_` en production, `sk_test_` ailleurs : une clé de test
        // utilisée par erreur en production doit être reconnaissable d'un coup
        // d'œil, sans avoir à la chercher en base.
        $prefix = app()->environment('production') ? 'sk_live_' : 'sk_test_';
        $plainKey = $prefix.Str::random(48);

        $key = ApiKey::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'prefix' => $prefix.substr(explode($prefix, $plainKey)[1], 0, 4),
            'key_hash' => ApiKey::hash($plainKey),
            'scopes' => array_values($scopes),
            'created_by' => $creator->id,
            'expires_at' => $expiresAt,
        ]);

        return new IssuedApiKey($key, $plainKey);
    }
}
