<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Domain\Models\PaymentAttempt;
use Throwable;

/**
 * Agrégateur Notch Pay.
 *
 * Deux différences de fond avec Tranzak, qui justifient à elles seules d'écrire
 * un adaptateur par agrégateur plutôt qu'un client générique :
 *
 *  1. **Notch Pay respecte les codes HTTP.** Un refus de validation est un
 *     `422`, pas un `200` avec un drapeau dans le corps. La détection d'un refus
 *     n'a donc rien de commun entre les deux.
 *
 *  2. **Le débit se fait en deux appels** — initialisation puis traitement. Cela
 *     **rétrécit la fenêtre dangereuse** : l'initialisation ne sollicite jamais
 *     le client, donc même une temporisation y reste basculable. Chez Tranzak,
 *     l'appel unique rend toute temporisation incertaine.
 *
 * @see docs/03-services/billing/05-providers.md
 */
final class NotchPayProvider implements PaymentProvider
{
    /**
     * Codes de refus **avant** toute sollicitation du client.
     *
     * Liste fermée et explicite, comme l'exige l'ADR-0008. Tout le reste vaut
     * « invite partie ». `429` en fait partie : un appel étranglé n'a pas été
     * traité.
     */
    private const REFUSED_BEFORE_PROMPT = [400, 401, 403, 404, 409, 422, 429];

    public function name(): string
    {
        return 'notchpay';
    }

    public function isConfigured(): bool
    {
        $key = config('billing.notchpay.public_key');

        return is_string($key) && $key !== '';
    }

    public function supports(string $operator): bool
    {
        return in_array($operator, ['mtn', 'orange'], true);
    }

    public function charge(ChargeRequest $request): ChargeOutcome
    {
        // --- Étape 1 : initialisation. Aucune invite ne part ici. ---------
        try {
            $init = $this->client()->post($this->url('/payments'), array_filter([
                'amount' => $request->money->amount,
                'currency' => $request->money->currency,
                'phone' => $request->msisdn->value,
                // Notre référence marchande, renvoyée ensuite en `trxref` :
                // c'est la clé de corrélation exigée par l'ADR-0008.
                'reference' => $request->merchantReference,
                'description' => $request->description,

                // URL de rappel **par paiement**, quand elle est configurée.
                //
                // Le tableau de bord n'en accepte qu'une : avec un seul compte
                // marchand, recette et production ne peuvent pas y coexister.
                // La renseigner ici permet à chaque environnement de recevoir
                // ses propres callbacks.
                //
                // Vide par défaut : le tableau de bord reste le mode normal, et
                // une URL figée dans des transactions passées survivrait à un
                // changement d'hébergement.
                'callback' => config('billing.notchpay.callback_url') ?: null,
            ], static fn ($value) => $value !== null));
        } catch (Throwable $exception) {
            // **Basculable malgré la temporisation.** L'initialisation ne
            // présente rien au client : au pire elle laisse un paiement orphelin
            // chez Notch Pay, sur lequel aucun argent ne bouge.
            Log::warning('Notch Pay : initialisation sans réponse.', [
                'merchant_reference' => $request->merchantReference,
                'error' => $exception->getMessage(),
            ]);

            return ChargeOutcome::rejected('PROVIDER_UNREACHABLE', $exception->getMessage());
        }

        if ($init->failed()) {
            return ChargeOutcome::rejected(
                $init->status() === 401 ? 'PROVIDER_AUTH_FAILED' : 'PROVIDER_REJECTED',
                $this->errorMessage($init),
                (string) $init->status(),
            );
        }

        $reference = $this->reference($init->json());

        if ($reference === null) {
            // Initialisée sans référence : le traitement est impossible, et
            // aucune invite ne peut partir. Basculable.
            return ChargeOutcome::rejected(
                'PROVIDER_REJECTED',
                'Réponse d\'initialisation sans référence de transaction.',
            );
        }

        // --- Étape 2 : traitement. C'est ici que l'invite part. ------------
        try {
            $process = $this->client()->post($this->url('/payments/'.$reference), [
                'channel' => $this->channelFor($request->msisdn->operator),
                'data' => ['phone' => $request->msisdn->value],
            ]);
        } catch (Throwable $exception) {
            // Ici l'incertitude redevient dangereuse : la demande a pu atteindre
            // Notch Pay et déclencher l'invite. Aucune bascule.
            Log::warning('Notch Pay : traitement sans réponse.', [
                'merchant_reference' => $request->merchantReference,
                'provider_ref' => $reference,
                'error' => $exception->getMessage(),
            ]);

            return ChargeOutcome::unknown($exception->getMessage(), $reference);
        }

        if (in_array($process->status(), self::REFUSED_BEFORE_PROMPT, true)) {
            // Refusé avant que le client ne soit sollicité. La référence est
            // conservée pour l'audit, mais aucune invite n'est partie.
            return ChargeOutcome::rejected(
                $process->status() === 401 ? 'PROVIDER_AUTH_FAILED' : 'PROVIDER_REJECTED',
                $this->errorMessage($process),
                (string) $process->status(),
                $reference,
            );
        }

        if ($process->failed()) {
            // 5xx : Notch Pay a peut-être traité la demande avant d'échouer.
            return ChargeOutcome::unknown('HTTP '.$process->status(), $reference);
        }

        return $this->translate($this->transactionStatus($process->json()), $reference, $process->json());
    }

    public function poll(PaymentAttempt $attempt): ChargeOutcome
    {
        if ($attempt->provider_ref === null) {
            return ChargeOutcome::unknown('Tentative sans référence fournisseur.');
        }

        try {
            $response = $this->client()->get($this->url('/payments/'.$attempt->provider_ref));
        } catch (Throwable $exception) {
            return ChargeOutcome::unknown($exception->getMessage(), $attempt->provider_ref);
        }

        if ($response->failed()) {
            // Au sondage, un refus ne rétrograde **jamais** une tentative en
            // `rejected` : il signifie que Notch Pay ne sait pas répondre sur
            // cette transaction, pas qu'il a refusé une demande. L'invite est
            // peut-être partie.
            return ChargeOutcome::unknown('HTTP '.$response->status(), $attempt->provider_ref);
        }

        return $this->translate(
            $this->transactionStatus($response->json()),
            $attempt->provider_ref,
            $response->json(),
        );
    }

    /**
     * Traduction du vocabulaire Notch Pay vers celui du module.
     *
     * **La partie la plus sensible de l'adaptateur.** Aucun statut n'y produit
     * de bascule : à ce stade l'appel de traitement a été accepté, donc le
     * client a été sollicité. Les refus, eux, sont détectés en amont sur le code
     * HTTP.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function translate(string $status, string $reference, ?array $body): ChargeOutcome
    {
        $transaction = $this->transaction($body);

        return match ($status) {
            'complete' => $this->settled($transaction, $reference, $status),

            'failed' => ChargeOutcome::failed(
                'PAYMENT_FAILED',
                (string) ($transaction['message'] ?? __('billing::messages.payment_failed')),
                $reference,
                $status,
            ),

            'canceled' => ChargeOutcome::failed(
                'PAYMENT_CANCELLED',
                __('billing::messages.payment_cancelled'),
                $reference,
                $status,
            ),

            'expired' => ChargeOutcome::failed(
                'PAYMENT_EXPIRED',
                __('billing::messages.payment_expired'),
                $reference,
                $status,
            ),

            // La documentation est explicite : après le traitement, « the
            // customer receives a prompt on their mobile device ».
            'processing' => ChargeOutcome::prompted($reference, $status),

            // `pending` signifie « initialisée, pas encore traitée ». Le
            // rencontrer après un traitement accepté est incohérent — donc
            // traité comme incertain, jamais comme un refus.
            'pending' => ChargeOutcome::unknown('Statut pending après traitement.', $reference, $status),

            default => ChargeOutcome::unknown('Statut inconnu : '.$status, $reference, $status),
        };
    }

    /**
     * Paiement abouti : brut, commission, net.
     *
     * La commission n'est **pas** un scalaire. Notch Pay renvoie un tableau
     * `fees`, vide en bac à sable — d'où l'absence de commission constatée lors
     * de la vérification. Sa forme en production reste donc **non vérifiée**,
     * et c'est écrit ici plutôt que supposé silencieusement.
     *
     * La lecture est au mieux : on somme les entrées exploitables, et l'échec de
     * lecture d'un tableau non vide est journalisé. Une commission inconnue
     * n'affecte ni le client ni la facture — celle-ci se règle sur le brut —
     * mais elle fausse la comptabilité de la plateforme, ce qui doit se voir.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function settled(array $transaction, string $reference, string $status): ChargeOutcome
    {
        $gross = $this->intOrNull($transaction, 'amount')
            ?? $this->intOrNull($this->section($transaction, 'amounts'), 'total');

        $fee = $this->fee($transaction);

        return ChargeOutcome::succeeded(
            providerRef: $reference,
            gross: $gross,
            fee: $fee,
            // Déduit, jamais inventé : Notch Pay ne renvoie pas de montant net.
            net: $gross !== null && $fee !== null ? $gross - $fee : null,
            raw: $status,
        );
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function fee(array $transaction): ?int
    {
        $fees = $transaction['fees'] ?? null;

        if (! is_array($fees) || $fees === []) {
            return null;
        }

        $total = 0;
        $read = 0;

        foreach ($fees as $entry) {
            if (is_numeric($entry)) {
                $total += (int) $entry;
                $read++;

                continue;
            }

            if (is_array($entry) && is_numeric($entry['amount'] ?? null)) {
                $total += (int) $entry['amount'];
                $read++;
            }
        }

        if ($read === 0) {
            Log::warning('Notch Pay : commission illisible, forme de `fees` inattendue.', [
                'reference' => $transaction['reference'] ?? null,
                'keys' => array_keys((array) ($fees[0] ?? [])),
            ]);

            return null;
        }

        return $total;
    }

    /**
     * Sous-objet d'une réponse, tolérant à son absence.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function section(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * Le réseau du payeur détermine le canal. Un numéro MTN ne se débite pas
     * sur le canal Orange.
     */
    private function channelFor(string $operator): string
    {
        $country = mb_strtolower((string) config('billing.default_country'));

        return $country.'.'.$operator;
    }

    private function client(): PendingRequest
    {
        // `Authorization` porte la clé **publique**, sans préfixe `Bearer` :
        // c'est la convention de Notch Pay. `X-Grant` n'est exigé que sur les
        // endpoints sensibles (soldes, transferts), qu'on n'utilise pas.
        return Http::withHeaders([
            'Authorization' => (string) config('billing.notchpay.public_key'),
            'Accept' => 'application/json',
        ])->timeout((int) config('billing.notchpay.timeout', 20));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('billing.notchpay.base_url'), '/').$path;
    }

    /**
     * Référence de transaction.
     *
     * Le champ `transaction` change de forme selon l'appel : une chaîne à
     * l'initialisation, un objet au traitement. La tolérance aux deux évite
     * qu'une réponse légitime soit lue comme une absence de référence — ce qui
     * autoriserait une bascule à tort.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function reference(?array $body): ?string
    {
        $transaction = $body['transaction'] ?? null;

        if (is_string($transaction) && $transaction !== '') {
            return $transaction;
        }

        if (is_array($transaction)) {
            foreach (['reference', 'id'] as $key) {
                if (is_string($transaction[$key] ?? null) && $transaction[$key] !== '') {
                    return $transaction[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function transaction(?array $body): array
    {
        $transaction = $body['transaction'] ?? null;

        return is_array($transaction) ? $transaction : (is_array($body) ? $body : []);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function transactionStatus(?array $body): string
    {
        return mb_strtolower((string) ($this->transaction($body)['status'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            // `errors` porte le détail par champ sur un 422 : il dit *pourquoi*
            // la demande a été refusée, ce que `message` seul ne dit pas.
            if (is_array($body['errors'] ?? null) && $body['errors'] !== []) {
                $first = reset($body['errors']);

                if (is_array($first) && isset($first[0]) && is_string($first[0])) {
                    return $first[0];
                }
            }

            if (is_string($body['message'] ?? null) && $body['message'] !== '') {
                return $body['message'];
            }
        }

        return __('billing::messages.provider_rejected');
    }
}
