<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\ApiKeyResolver;
use Modules\Payments\Application\External\CreateExternalCharge;
use Modules\Payments\Application\External\DeclaredCharge;
use Modules\Payments\Domain\Models\ExternalCharge;
use Modules\Payments\Presentation\Http\Requests\CreateChargeRequest;

/**
 * Encaissement pour un produit qui ne partage pas cette base de code.
 *
 * ## Pourquoi cette route existe alors que `POST /payments` n'existe pas
 *
 * La règle n'a jamais été « aucune route de création ici ». Elle est : **seul le
 * propriétaire de l'objet nomme son prix.** Un module du monolithe le prouve en
 * implémentant une interface ; un service externe le prouve en présentant une
 * clé qui porte la liste des `subject_type` qu'il possède.
 *
 * La propriété survit, le mécanisme change.
 *
 * ## Ce que cette route ne peut pas offrir
 *
 * L'atomicité. Chez un module interne, « l'argent est encaissé » et « le service
 * est ouvert » sont vrais au même instant, dans la même transaction. Un service
 * externe ne participe pas à cette transaction : il existe une fenêtre pendant
 * laquelle un client a payé et n'a pas son service.
 *
 * Elle est irréductible. Elle n'est que rendue courte — webhook — et rattrapable
 * — sondage et réconciliation, tous deux obligatoires.
 *
 * @see docs/03-services/payments/07-external-api.md
 * @see docs/04-decisions/adr-0010-external-payment-api.md
 */
final class ChargeController
{
    public function __construct(private readonly ApiKeyResolver $keys) {}

    public function store(CreateChargeRequest $request, CreateExternalCharge $charges): JsonResponse
    {
        $key = $this->keys->require($request, 'payments.charge');
        $subjectType = $request->string('subject_type')->toString();

        $this->guardSubjectType($key->key->subject_types, $subjectType);

        $declared = $charges->handle(
            organizationId: $key->organizationId(),
            apiKeyId: $key->key->id,
            subjectType: $subjectType,
            subjectId: $request->string('subject_id')->toString(),
            payerType: $request->string('payer_type')->toString(),
            payerId: $request->string('payer_id')->toString(),
            amount: $request->integer('amount'),
            currency: $request->string('currency')->toString(),
            description: $request->string('description')->toString(),
            rawMsisdn: $request->string('msisdn')->toString(),
            idempotencyKey: $request->header('Idempotency-Key'),
        );

        // `202` et non `201` : ce qui est créé est une **intention**. Le client
        // n'a pas encore validé quoi que ce soit sur son téléphone.
        return ApiResponse::success($this->present($declared), status: 202);
    }

    /**
     * Sonder — le second des trois mécanismes, et il n'est pas optionnel.
     *
     * Un webhook se perd. Un produit qui ne met en place que lui aura, tôt ou
     * tard, un client payé sans service et aucun moyen de s'en apercevoir.
     */
    public function show(Request $request, string $chargeId): JsonResponse
    {
        $key = $this->keys->require($request, 'payments.read');

        $charge = ExternalCharge::query()
            ->where('organization_id', $key->organizationId())
            ->whereKey($chargeId)
            ->first();

        if ($charge === null) {
            throw DomainException::notFound(
                'RESOURCE_NOT_FOUND',
                __('payments::messages.external_charge_not_found'),
            );
        }

        $response = ApiResponse::success($this->presentCharge($charge));

        if (! $charge->isSettled()) {
            $response->header('Retry-After', '5');
        }

        return $response;
    }

    /**
     * Réconcilier — le troisième mécanisme, et le seul filet quand les deux
     * autres ont échoué.
     *
     * Sans lui, un client payé sans service reste invisible jusqu'à sa
     * réclamation.
     */
    public function index(Request $request): JsonResponse
    {
        $key = $this->keys->require($request, 'payments.read');

        $charges = ExternalCharge::query()
            ->where('organization_id', $key->organizationId())
            ->when(
                $request->filled('since'),
                fn ($q) => $q->where('created_at', '>=', $request->date('since')),
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')->toString()),
            )
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return ApiResponse::success(
            $charges->map($this->presentCharge(...))->all(),
        );
    }

    /**
     * La clé porte-t-elle ce type d'objet ?
     *
     * Sans cette borne, une clé fuitée permettrait de déclarer un prix sur les
     * objets de **tous** les produits — de payer 100 XAF une formation à 15 000,
     * ou de manipuler une facture d'abonnement.
     *
     * Le même code pour « non autorisé » et pour « type inconnu » : deux
     * réponses distinctes permettraient d'énumérer les types servis par la
     * plateforme.
     *
     * @param  list<string>|null  $allowed
     */
    private function guardSubjectType(?array $allowed, string $subjectType): void
    {
        if (! in_array($subjectType, (array) ($allowed ?? []), true)) {
            throw DomainException::forbidden(
                'SUBJECT_TYPE_NOT_ALLOWED',
                __('payments::messages.subject_type_not_allowed'),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DeclaredCharge $declared): array
    {
        $intent = $declared->intent;

        return [
            'charge_id' => $declared->charge->id,
            'payment_id' => $intent->id,
            'status' => $intent->status,
            'operator' => $intent->operator,
            ...$intent->money()->toArray(),
            'expires_at' => $intent->expires_at->toIso8601ZuluString(),
            'instructions' => __('payments::messages.payment_instructions'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCharge(ExternalCharge $charge): array
    {
        return [
            'charge_id' => $charge->id,
            'payment_id' => $charge->payment_intent_id,
            'subject_type' => $charge->subject_type,
            'subject_id' => $charge->subject_id,
            'payer_type' => $charge->payer_type,
            'payer_id' => $charge->payer_id,

            // `expired` signifie **on ne sait pas**, et non « cela a échoué ».
            // Un paiement dont l'issue est inconnue peut avoir été encaissé :
            // le traiter comme un échec risquerait de facturer deux fois.
            'status' => $charge->status,

            ...$charge->money()->toArray(),
            'description' => $charge->description,
            'created_at' => $charge->created_at?->toIso8601ZuluString(),
            'settled_at' => $charge->settled_at?->toIso8601ZuluString(),
        ];
    }
}
