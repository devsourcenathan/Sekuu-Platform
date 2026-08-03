<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Domain\Models\PaymentAttempt;
use Throwable;

/**
 * Agrégateur Tranzak.
 *
 * Premier écrit des trois, bien qu'il ne soit pas nécessairement premier en
 * priorité d'exécution : il est le seul à documenter un bac à sable et un
 * endpoint de rafraîchissement de statut. Écrire un adaptateur de paiement sans
 * environnement de test reproduirait le canal SMS de Notify — écrit
 * intégralement, jamais exécuté contre une vraie passerelle.
 *
 * @see docs/03-services/billing/05-providers.md
 */
final class TranzakProvider implements PaymentProvider
{
    /**
     * Statuts Tranzak signifiant que l'invite **n'est jamais partie**.
     *
     * Liste volontairement fermée et vide. Tout le reste vaut « invite
     * partie » : le défaut penche du côté qui ne débite pas deux fois.
     *
     * `CANCELLED` — annulation par le système — pourrait en faire partie s'il
     * signifie que la demande n'a jamais atteint le client. Le sens exact est à
     * confirmer auprès de Tranzak ; c'est la seule vérification susceptible
     * d'élargir la règle de bascule.
     */
    private const NEVER_PROMPTED = [];

    public function name(): string
    {
        return 'tranzak';
    }

    public function isConfigured(): bool
    {
        return is_string(config('billing.tranzak.app_id')) && config('billing.tranzak.app_id') !== ''
            && is_string(config('billing.tranzak.app_key')) && config('billing.tranzak.app_key') !== '';
    }

    /** Tranzak couvre MTN Cameroon Mobile Money et Orange Money Cameroon. */
    public function supports(string $operator): bool
    {
        return in_array($operator, ['mtn', 'orange'], true);
    }

    public function charge(ChargeRequest $request): ChargeOutcome
    {
        try {
            $token = $this->token();
        } catch (Throwable $exception) {
            // Authentification impossible : la demande n'a jamais quitté la
            // plateforme, donc le client n'a rien reçu. C'est un des rares cas
            // qui autorisent la bascule.
            return ChargeOutcome::rejected(
                'PROVIDER_AUTH_FAILED',
                $exception->getMessage(),
            );
        }

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('billing.tranzak.timeout', 20))
                ->acceptJson()
                ->post($this->url('/xp021/v1/request/create-mobile-wallet-charge'), [
                    'amount' => $request->money->amount,
                    'currencyCode' => $request->money->currency,
                    'mobileWalletNumber' => $request->msisdn->value,
                    'mchTransactionRef' => $request->merchantReference,
                    'description' => $request->description,
                ]);
        } catch (Throwable $exception) {
            // Temporisation ou panne réseau : on **ignore** si la demande a
            // atteint Tranzak. C'est exactement le cas où réessayer ailleurs
            // double-débiterait.
            Log::warning('Tranzak : appel de débit sans réponse.', [
                'merchant_reference' => $request->merchantReference,
                'error' => $exception->getMessage(),
            ]);

            return ChargeOutcome::unknown($exception->getMessage());
        }

        // Erreurs d'authentification et de validation : la demande a été
        // refusée avant toute sollicitation du client.
        if (in_array($response->status(), [400, 401, 403, 422], true)) {
            return ChargeOutcome::rejected(
                'PROVIDER_REJECTED',
                $this->errorMessage($response->json()),
                $this->rawStatus($response->json()),
            );
        }

        if ($response->failed()) {
            // 5xx : Tranzak a peut-être traité la demande avant d'échouer.
            return ChargeOutcome::unknown('HTTP '.$response->status());
        }

        $data = $this->data($response->json());
        $providerRef = $this->providerRef($data);

        if ($providerRef === null) {
            // Réponse acceptée sans référence : on ne pourra plus rien
            // retrouver. Traité comme incertain, jamais comme un rejet.
            return ChargeOutcome::unknown('Réponse sans identifiant de transaction.');
        }

        return $this->translate($data, $providerRef);
    }

    public function poll(PaymentAttempt $attempt): ChargeOutcome
    {
        if ($attempt->provider_ref === null) {
            return ChargeOutcome::unknown('Tentative sans référence fournisseur.');
        }

        try {
            $response = Http::withToken($this->token())
                ->timeout((int) config('billing.tranzak.timeout', 20))
                ->acceptJson()
                ->get($this->url('/xp021/v1/request/details'), [
                    'requestId' => $attempt->provider_ref,
                ]);
        } catch (Throwable $exception) {
            return ChargeOutcome::unknown($exception->getMessage(), $attempt->provider_ref);
        }

        if ($response->failed()) {
            return ChargeOutcome::unknown('HTTP '.$response->status(), $attempt->provider_ref);
        }

        return $this->translate($this->data($response->json()), $attempt->provider_ref);
    }

    /**
     * Traduction du vocabulaire Tranzak vers celui du module.
     *
     * **La partie la plus sensible de tout le module.** Confondre un rejet
     * avant invite avec un échec après invite autorise une bascule qui
     * double-débite le client.
     *
     * @param  array<string, mixed>  $data
     */
    private function translate(array $data, string $providerRef): ChargeOutcome
    {
        $status = mb_strtoupper((string) ($data['status'] ?? ''));

        // Garde-fou explicite : si un statut apparaissait un jour dans la liste
        // des « jamais sollicité », il serait traité comme tel. La liste est
        // vide aujourd'hui, et c'est délibéré.
        if (in_array($status, self::NEVER_PROMPTED, true)) {
            return ChargeOutcome::rejected('PROVIDER_REJECTED', $status, $status);
        }

        return match ($status) {
            'SUCCESSFUL' => ChargeOutcome::succeeded(
                providerRef: $providerRef,
                gross: $this->intOrNull($data, 'amount'),
                fee: $this->intOrNull($data, 'merchantFee') ?? $this->intOrNull($data, 'fee'),
                net: $this->intOrNull($data, 'netAmountReceived'),
                raw: $status,
            ),

            // Le seul statut de tout l'écosystème qui **prouve** que le client a
            // été sollicité : il n'aurait pas pu annuler sans avoir reçu
            // l'invite.
            'CANCELLED_BY_PAYER' => ChargeOutcome::failed(
                'PAYER_CANCELLED',
                __('billing::messages.payment_cancelled_by_payer'),
                $providerRef,
                $status,
            ),

            'FAILED' => ChargeOutcome::failed(
                'PAYMENT_FAILED',
                (string) ($data['statusMessage'] ?? __('billing::messages.payment_failed')),
                $providerRef,
                $status,
            ),

            // Ambigu : annulation par le système. Pourrait signifier que la
            // demande n'a jamais atteint le client — à confirmer auprès de
            // Tranzak. Faute de certitude, traité comme un échec, pas comme un
            // rejet : cela interdit la bascule.
            'CANCELLED' => ChargeOutcome::failed(
                'PAYMENT_CANCELLED',
                __('billing::messages.payment_cancelled'),
                $providerRef,
                $status,
            ),

            // Concerne le flux de redirection web, pas le débit direct. Le
            // rencontrer ici signale une erreur d'intégration, pas un état
            // normal.
            'PAYER_REDIRECT_REQUIRED' => ChargeOutcome::failed(
                'PAYMENT_FAILED',
                __('billing::messages.payment_failed'),
                $providerRef,
                $status,
            ),

            'PENDING' => ChargeOutcome::prompted($providerRef, $status),
            'PAYMENT_IN_PROGRESS' => ChargeOutcome::processing($providerRef, $status),

            // Statut inconnu : traité comme « invite partie ». Ne jamais
            // supposer qu'un statut qu'on ne comprend pas est inoffensif.
            default => ChargeOutcome::unknown('Statut inconnu : '.$status, $providerRef, $status),
        };
    }

    /**
     * Jeton porteur, mis en cache à ~75 % de sa validité comme le recommande
     * Tranzak. Le redemander à chaque appel ajouterait un aller-retour, et un
     * point de panne, sur le chemin de l'argent.
     */
    private function token(): string
    {
        return Cache::remember('billing:tranzak:token', now()->addMinutes(90), function (): string {
            $response = Http::timeout((int) config('billing.tranzak.timeout', 20))
                ->acceptJson()
                ->post($this->url('/auth/token'), [
                    'appId' => config('billing.tranzak.app_id'),
                    'appKey' => config('billing.tranzak.app_key'),
                ])
                ->throw();

            $token = $this->data($response->json())['token'] ?? null;

            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Tranzak : jeton absent de la réponse.');
            }

            return $token;
        });
    }

    private function url(string $path): string
    {
        return rtrim((string) config('billing.tranzak.base_url'), '/').$path;
    }

    /**
     * Tranzak enveloppe ses réponses dans `data`. La tolérance à l'absence
     * d'enveloppe évite qu'un changement de forme ne casse tout l'adaptateur.
     *
     * @return array<string, mixed>
     */
    private function data(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function providerRef(array $data): ?string
    {
        foreach (['requestId', 'transactionId', 'id'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function errorMessage(mixed $body): string
    {
        if (is_array($body)) {
            foreach (['errorMsg', 'message', 'error'] as $key) {
                if (is_string($body[$key] ?? null) && $body[$key] !== '') {
                    return $body[$key];
                }
            }
        }

        return __('billing::messages.provider_rejected');
    }

    private function rawStatus(mixed $body): ?string
    {
        return is_array($body) && is_string($body['status'] ?? null) ? $body['status'] : null;
    }
}
