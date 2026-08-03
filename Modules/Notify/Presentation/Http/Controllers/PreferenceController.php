<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Notify\Application\Preferences\Unsubscribe;
use Modules\Notify\Application\Preferences\UnsubscribeToken;
use Modules\Notify\Domain\Category;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\NotificationPreference;
use Modules\Notify\Presentation\Http\Requests\UpdatePreferencesRequest;

/**
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class PreferenceController
{
    public function index(AuthenticatedContext $context): JsonResponse
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $context->user->id)
            ->whereNull('organization_id')
            ->get();

        $preferences = [];

        foreach (Category::all() as $category) {
            foreach ([Channel::EMAIL, Channel::SMS, Channel::WHATSAPP, Channel::PUSH] as $channel) {
                $match = $stored->first(
                    fn (NotificationPreference $p) => $p->category === $category && $p->channel === $channel
                );

                $preferences[] = [
                    'category' => $category,
                    'channel' => $channel,
                    'enabled' => $match?->enabled ?? Category::enabledByDefault($category),
                    // L'interface doit pouvoir griser ce qui n'est pas
                    // modifiable, plutôt que de laisser l'utilisateur découvrir
                    // le refus après coup.
                    'editable' => Category::isOptional($category),
                ];
            }
        }

        return ApiResponse::success($preferences);
    }

    public function update(UpdatePreferencesRequest $request, AuthenticatedContext $context): JsonResponse
    {
        foreach ($request->validated()['preferences'] as $preference) {
            if (! Category::isOptional($preference['category'])) {
                throw DomainException::unprocessable(
                    'TRANSACTIONAL_CANNOT_BE_DISABLED',
                    __('notify::messages.transactional_locked'),
                );
            }

            NotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $context->user->id,
                    'organization_id' => null,
                    'category' => $preference['category'],
                    'channel' => $preference['channel'],
                ],
                ['enabled' => $preference['enabled']],
            );
        }

        return $this->index($context);
    }

    /**
     * Contexte d'un lien de désabonnement. **Public** : exiger une connexion
     * pour se désabonner est une pratique hostile, et pousse vers le bouton
     * « spam » — bien plus coûteux, puisqu'il alimente la liste de suppression.
     */
    public function showUnsubscribe(string $token): JsonResponse
    {
        $payload = UnsubscribeToken::open($token);

        return ApiResponse::success([
            'destination' => self::mask($payload['destination']),
            'channel' => $payload['channel'],
            'category' => $payload['category'],
            'editable' => Category::isOptional($payload['category']),
        ]);
    }

    public function unsubscribe(string $token, Unsubscribe $unsubscribe): JsonResponse
    {
        $payload = UnsubscribeToken::open($token);

        $effect = $unsubscribe->handle($payload);

        return ApiResponse::success([
            'category' => $payload['category'],
            'channel' => $payload['channel'],
            // `preference` : réversible depuis le profil.
            // `suppression` : le destinataire n'a pas de compte, la destination
            // est bloquée entièrement.
            'effect' => $effect,
        ]);
    }

    private static function mask(string $destination): string
    {
        if (! str_contains($destination, '@')) {
            return str_repeat('*', max(0, mb_strlen($destination) - 4)).mb_substr($destination, -4);
        }

        [$local, $domain] = explode('@', $destination, 2);

        return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }
}
