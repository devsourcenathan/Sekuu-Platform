<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use App\Platform\Http\Concerns\ResolvesOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Domain\Models\PaymentAttempt;
use Modules\Payments\Domain\Models\PaymentIntent;

/**
 * Consultation des paiements.
 *
 * **Lecture seule.** Déclencher un paiement appartient au module qui possède
 * l'objet payé : lui seul sait ce qu'il vaut et qui a le droit de le régler.
 * Payments n'expose donc aucune route de création — il offrirait sinon un
 * moyen de faire sonner le téléphone de quelqu'un sans savoir pourquoi.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class PaymentController
{
    use ResolvesOrganization;

    /**
     * Rôles autorisés à voir le détail des tentatives.
     *
     * L'ordre de priorité des agrégateurs est une information d'exploitation :
     * un membre ordinaire n'a pas à savoir chez qui la plateforme encaisse.
     *
     * @var list<string>
     */
    private const ROLES_DETAIL = ['owner', 'admin'];

    public function show(Request $request, string $paymentId): JsonResponse
    {
        $intent = $this->scoped()->whereKey($paymentId)->with('attempts')->first();

        if ($intent === null) {
            throw DomainException::notFound(
                'RESOURCE_NOT_FOUND',
                __('payments::messages.payment_not_found'),
            );
        }

        $response = ApiResponse::success($this->present($intent, $this->hasRole(self::ROLES_DETAIL)));

        // Sonder est le mode d'emploi de cet endpoint : le dire dans l'en-tête
        // évite que chaque client invente son propre rythme.
        if (! $intent->isSettled()) {
            $response->header('Retry-After', '5');
        }

        return $response;
    }

    public function index(Request $request): JsonResponse
    {
        $intents = $this->scoped()->orderByDesc('created_at')->limit(50)->get();

        return ApiResponse::success($intents->map(fn (PaymentIntent $i) => $this->present($i))->all());
    }

    /**
     * Les paiements de l'organisation courante, **en tant que payeuse**.
     *
     * Le jour où un tiers encaisse via la plateforme, il consultera les siens
     * par `payee_organization_id` : deux questions distinctes, deux requêtes
     * distinctes.
     */
    private function scoped(): Builder
    {
        return PaymentIntent::query()
            ->where('payer_type', PaymentIntent::PAYER_ORGANIZATION)
            ->where('payer_id', $this->organizationId());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PaymentIntent $intent, bool $detailed = false): array
    {
        $payload = [
            'id' => $intent->id,
            'status' => $intent->status,
            'operator' => $intent->operator,
            'subject_type' => $intent->subject_type,
            'subject_id' => $intent->subject_id,
            ...$intent->money()->toArray(),
            'expires_at' => $intent->expires_at->toIso8601ZuluString(),
            'failure_code' => $intent->failure_code,
        ];

        if ($intent->status === PaymentIntent::PENDING) {
            $payload['instructions'] = __('payments::messages.payment_instructions');
        }

        if (! $detailed) {
            return $payload;
        }

        $payload['attempts'] = $intent->attempts->map(fn (PaymentAttempt $attempt): array => [
            'provider' => $attempt->provider,
            'status' => $attempt->status->value,

            // Explique pourquoi la bascule s'est arrêtée là : une fois l'invite
            // partie, on n'essaie plus ailleurs.
            'customer_prompted' => $attempt->customer_prompted,

            'failure_code' => $attempt->failure_code,
            'started_at' => $attempt->started_at->toIso8601ZuluString(),
        ])->all();

        return $payload;
    }
}
