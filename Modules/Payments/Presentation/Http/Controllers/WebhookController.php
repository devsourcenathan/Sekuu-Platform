<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Application\Payments\SettlePayment;
use Modules\Payments\Domain\Models\PaymentAttempt;
use Modules\Payments\Domain\Models\ProviderEvent;
use Modules\Payments\Infrastructure\Providers\ProviderRegistry;
use Modules\Payments\Infrastructure\Webhooks\WebhookRegistry;

/**
 * Callbacks des agrégateurs.
 *
 * Publique au sens réseau, authentifiée par signature ou secret partagé.
 *
 * **Un callback n'est jamais cru sur parole.** Il déclenche une relecture du
 * statut chez l'agrégateur, il ne le dicte pas. Chez Tranzak, l'authentification
 * passe par un `authKey` transporté dans le corps : cela prouve que l'émetteur
 * connaît le secret, jamais que le corps est intact.
 *
 * @see docs/03-services/billing/05-providers.md
 */
final class WebhookController
{
    public function __invoke(
        Request $request,
        WebhookRegistry $webhooks,
        ProviderRegistry $providers,
        SettlePayment $settlement,
        string $provider,
    ): JsonResponse {
        $handler = $webhooks->for($provider);
        $valid = $handler->verify($request);

        $event = $this->record($provider, $handler->eventId($request), $request->all(), $valid);

        if (! $valid) {
            // Enregistré tout de même : jeter en silence priverait de toute
            // trace en cas de tentative de fraude.
            throw new DomainException(
                'WEBHOOK_SIGNATURE_INVALID',
                __('payments::messages.webhook_signature_invalid'),
                401,
            );
        }

        // Déjà traité : on ne refait rien. La déduplication s'appuie sur
        // l'unicité en base, pas sur une vérification applicative qui perdrait
        // la course sous concurrence.
        if ($event === null) {
            return ApiResponse::success(['processed' => false, 'reason' => 'duplicate']);
        }

        $providerRef = $handler->providerRef($request);

        $attempt = $providerRef === null ? null : PaymentAttempt::query()
            ->where('provider', $provider)
            ->where('provider_ref', $providerRef)
            ->with('intent')
            ->first();

        if ($attempt === null) {
            // Callback sans tentative correspondante : cas qui doit rester
            // **visible**. Il signale soit une erreur de configuration entre
            // environnements, soit un callback qui ne nous était pas destiné.
            $event->forceFill([
                'processed_at' => now(),
                'error' => 'Aucune tentative correspondante.',
            ])->save();

            return ApiResponse::success(['processed' => false, 'reason' => 'unknown_reference']);
        }

        $event->forceFill(['payment_attempt_id' => $attempt->id])->save();

        // Le statut est **relu chez l'agrégateur**, jamais pris dans le corps
        // du callback. C'est ce qui neutralise un rejeu modifié.
        $outcome = $providers->byName($provider)->poll($attempt);

        $settlement->applyToAttempt($attempt, $outcome);

        if ($attempt->fresh()->status->isTerminal() && $attempt->intent !== null) {
            $settlement->applyToIntent($attempt->intent->fresh(), $attempt->fresh());
        }

        $event->forceFill(['processed_at' => now()])->save();

        // Toujours 200 : répondre en erreur déclencherait des réessais inutiles
        // chez l'agrégateur, et finirait par faire désactiver le endpoint.
        return ApiResponse::success(['processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $provider, string $eventId, array $payload, bool $valid): ?ProviderEvent
    {
        try {
            // Transaction imbriquée : sur PostgreSQL, une violation d'unicité
            // avorte la transaction courante et fait échouer toutes les requêtes
            // suivantes. Le SAVEPOINT permet de la rattraper sans tout perdre.
            return DB::transaction(fn (): ProviderEvent => ProviderEvent::create([
                'provider' => $provider,
                'provider_event_id' => $eventId,
                // Le secret partagé n'est pas conservé : il servirait à forger
                // un callback valide à quiconque lirait la table.
                'payload' => array_diff_key($payload, ['authKey' => null]),
                'signature_valid' => $valid,
                'received_at' => now(),
            ]));
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                return null;
            }

            throw $exception;
        }
    }
}
