<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

use App\Platform\Exceptions\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Domain\Models\AiAccount;

/**
 * Le protocole d'OpenAI — **pas l'entreprise**.
 *
 * Il sert OpenAI, Google par son point d'accès compatible, DeepSeek, Mistral,
 * xAI, Groq, Together, Fireworks, DeepInfra, OpenRouter, Azure et les serveurs
 * locaux. Ils n'en diffèrent que par une URL de base.
 *
 * C'est exactement la situation du pilote `s3` de Storage, et la conséquence est
 * la même : ajouter l'un de ces services est une ligne de configuration.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class OpenAiDriver implements AiDriver
{
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(json: true, tools: true, history: true);
    }

    /**
     * Un compte peut restreindre sa liste ; sinon on lui fait confiance.
     *
     * Interroger le fournisseur à chaque appel coûterait une requête de plus
     * pour une information qui change rarement — et un modèle refusé se
     * signalera de toute façon à la génération, avec un message plus précis que
     * n'importe quelle liste.
     */
    public function serves(AiAccount $account, string $model): bool
    {
        $autorises = $account->models;

        return $autorises === null || $autorises === [] || in_array($model, $autorises, true);
    }

    public function generate(AiAccount $account, GenerationRequest $request): GenerationResult
    {
        $debut = (int) (microtime(true) * 1000);

        $corps = [
            'model' => $request->model,
            'messages' => $this->messages($request),
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ];

        if ($request->json) {
            $corps['response_format'] = ['type' => 'json_object'];
        }

        try {
            $reponse = $this->client($account)->post('/chat/completions', $corps);
        } catch (ConnectionException $e) {
            throw ProviderFailure::from($e);
        }

        if ($reponse->failed()) {
            throw $this->failure($reponse->status(), (string) $reponse->body());
        }

        $donnees = (array) $reponse->json();

        return new GenerationResult(
            output: (string) ($donnees['choices'][0]['message']['content'] ?? ''),

            /*
             * Les jetons **rapportés par le fournisseur**, jamais estimés.
             *
             * C'est ce qui rend le coût imputable. Une estimation locale
             * divergerait de la facture, et la divergence ne se verrait qu'en
             * fin de mois.
             */
            inputTokens: (int) ($donnees['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($donnees['usage']['completion_tokens'] ?? 0),

            latencyMs: (int) (microtime(true) * 1000) - $debut,
        );
    }

    public function probe(AiAccount $account): void
    {
        $modele = ($account->models[0] ?? null) ?? throw DomainException::unprocessable(
            'AI_ACCOUNT_UNVERIFIED',
            __('ai::messages.probe_needs_model'),
        );

        // Un jeton, sur le plus petit modèle déclaré. Ça coûte une fraction de
        // centime — et une épreuve qui ne consommerait rien ne prouverait pas
        // qu'on peut générer.
        $this->generate($account, new GenerationRequest(
            model: $modele,
            prompt: 'ping',
            instructions: null,
            maxOutputTokens: 1,
            temperature: 0.0,
            json: false,
        ));
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function messages(GenerationRequest $request): array
    {
        $messages = [];

        if ($request->instructions !== null) {
            $messages[] = ['role' => 'system', 'content' => $request->instructions];
        }

        // L'historique vient de l'appelant : le module ne garde aucun fil, un
        // fil est de la logique produit.
        foreach ($request->history as $tour) {
            $messages[] = ['role' => $tour['role'], 'content' => $tour['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $request->prompt];

        return $messages;
    }

    private function client(AiAccount $account)
    {
        $base = $account->baseUrl()
            ?? throw DomainException::unprocessable('AI_ACCOUNT_UNVERIFIED', __('ai::messages.no_base_url'));

        // Azure authentifie par `api-key`, les autres par `Authorization`. Deux
        // en-têtes, toujours pas de pilote de plus.
        $entetes = ($account->config['auth'] ?? null) === 'api-key'
            ? ['api-key' => (string) $account->apiKey()]
            : ['Authorization' => 'Bearer '.$account->apiKey()];

        return Http::withHeaders($entetes)
            ->baseUrl(rtrim($base, '/'))
            ->timeout((int) config('ai.request_timeout', 120))
            ->acceptJson();
    }

    /**
     * La classification fine appartient à la couche au-dessus ; ici on
     * distingue seulement ce que le statut HTTP permet de distinguer sans
     * ambiguïté.
     */
    private function failure(int $status, string $body): DomainException
    {
        $message = mb_substr($body, 0, 500);

        return match (true) {
            in_array($status, [401, 403], true) => new DomainException('AI_CREDENTIALS_REJECTED', $message, 503),

            /*
             * Le crédit est épuisé — à distinguer d'un simple débit trop
             * rapide, qui porte souvent le même statut. L'un se résout en
             * quelques secondes, l'autre demande une carte bancaire.
             */
            $status === 402, ProviderFailure::looksLikeExhaustedCredit($message) => new DomainException('AI_CREDIT_EXHAUSTED', $message, 503),

            $status === 429 => new DomainException('AI_RATE_LIMITED', $message, 503),
            $status === 404 => new DomainException('AI_MODEL_UNAVAILABLE', $message, 503),

            /*
             * Un refus de modération.
             *
             * Il ne se réessaie **nulle part** : contourné en changeant de
             * fournisseur, ce serait un contournement réussi — et personne n'en
             * veut, ni nous, ni le fournisseur, ni le client le jour où cela se
             * sait.
             */
            $status === 400 && ProviderFailure::looksLikeModeration($message) => new DomainException('CONTENT_FLAGGED', $message, 422),

            default => new DomainException('AI_PROVIDER_ERROR', $message, 503),
        };
    }
}
