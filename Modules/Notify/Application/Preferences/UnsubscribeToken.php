<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Preferences;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\Config;

/**
 * Jeton de désabonnement, signé et sans état.
 *
 * Il est délibérément **sans expiration** : un lien de désabonnement figure
 * dans un message que le destinataire peut rouvrir des mois plus tard, et un
 * lien périmé le pousserait vers le bouton « spam » — bien plus coûteux qu'un
 * désabonnement, puisqu'il alimente la liste de suppression.
 *
 * Il est aussi sans état : le stocker imposerait une ligne par message envoyé.
 *
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class UnsubscribeToken
{
    public static function issue(
        string $channel,
        string $destination,
        string $category,
        ?string $userId = null,
    ): string {
        $payload = [
            'c' => $channel,
            'd' => mb_strtolower($destination),
            'g' => $category,
            'u' => $userId,
        ];

        $body = self::encode((string) json_encode($payload));

        return $body.'.'.self::sign($body);
    }

    /**
     * @return array{channel: string, destination: string, category: string, user_id: ?string}
     */
    public static function open(string $token): array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || ! hash_equals(self::sign($parts[0]), $parts[1])) {
            throw new DomainException(
                'UNSUBSCRIBE_TOKEN_INVALID',
                __('notify::messages.unsubscribe_token_invalid'),
                400,
            );
        }

        $payload = json_decode((string) self::decode($parts[0]), true);

        if (! is_array($payload) || ! isset($payload['c'], $payload['d'], $payload['g'])) {
            throw new DomainException(
                'UNSUBSCRIBE_TOKEN_INVALID',
                __('notify::messages.unsubscribe_token_invalid'),
                400,
            );
        }

        return [
            'channel' => (string) $payload['c'],
            'destination' => (string) $payload['d'],
            'category' => (string) $payload['g'],
            'user_id' => isset($payload['u']) ? (string) $payload['u'] : null,
        ];
    }

    private static function sign(string $body): string
    {
        return self::encode(hash_hmac('sha256', $body, (string) Config::get('app.key'), true));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
