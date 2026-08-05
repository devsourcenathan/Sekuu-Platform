<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Domain\Models\AiAccount;

/**
 * Le protocole d'Anthropic.
 *
 * Il a le sien, et c'est ce qui justifie une classe plutôt qu'un préréglage :
 * en-tête `x-api-key` au lieu de `Authorization`, une version d'API
 * obligatoire, les instructions dans un champ `system` séparé plutôt que dans
 * les messages, et un usage rapporté sous d'autres noms.
 *
 * Aucun paramètre ne transforme un client OpenAI en client Anthropic. C'est la
 * limite exacte posée par l'ADR-0017 : ajouter un **service** est une donnée,
 * ajouter une **famille d'authentification** est du code.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class AnthropicDriver implements AiDriver
{
    /**
     * Figée dans le code, jamais dans la configuration.
     *
     * C'est un contrat de format, pas un réglage : la changer modifie la forme
     * des réponses, ce qui appartient à une revue et non à un tableau de bord.
     */
    private const API_VERSION = '2023-06-01';

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(json: true, tools: true, history: true);
    }

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
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
            'messages' => $this->messages($request),
        ];

        // Les instructions vont dans un champ à part, jamais dans les messages.
        if ($request->instructions !== null) {
            $corps['system'] = $request->instructions;
        }

        $reponse = $this->client($account)->post('/v1/messages', $corps);

        if ($reponse->failed()) {
            throw $this->failure($reponse->status(), (string) $reponse->body());
        }

        $donnees = (array) $reponse->json();

        return new GenerationResult(
            output: $this->text($donnees),
            inputTokens: (int) ($donnees['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($donnees['usage']['output_tokens'] ?? 0),
            latencyMs: (int) (microtime(true) * 1000) - $debut,
        );
    }

    public function probe(AiAccount $account): void
    {
        $modele = ($account->models[0] ?? null) ?? throw DomainException::unprocessable(
            'AI_ACCOUNT_UNVERIFIED',
            __('ai::messages.probe_needs_model'),
        );

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
     * La réponse est une liste de blocs, pas une chaîne.
     *
     * Un modèle peut en rendre plusieurs — du texte, un appel d'outil. On ne
     * concatène que le texte : joindre le reste produirait une sortie
     * silencieusement fausse.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function text(array $donnees): string
    {
        $morceaux = [];

        foreach ((array) ($donnees['content'] ?? []) as $bloc) {
            if (($bloc['type'] ?? null) === 'text') {
                $morceaux[] = (string) $bloc['text'];
            }
        }

        return implode('', $morceaux);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function messages(GenerationRequest $request): array
    {
        $messages = [];

        foreach ($request->history as $tour) {
            $messages[] = ['role' => $tour['role'], 'content' => $tour['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $request->prompt];

        return $messages;
    }

    private function client(AiAccount $account)
    {
        return Http::withHeaders([
            'x-api-key' => (string) $account->apiKey(),
            'anthropic-version' => self::API_VERSION,
        ])
            ->baseUrl(rtrim($account->baseUrl() ?? 'https://api.anthropic.com', '/'))
            ->timeout((int) config('ai.request_timeout', 120))
            ->acceptJson();
    }

    private function failure(int $status, string $body): DomainException
    {
        $message = mb_substr($body, 0, 500);

        return match (true) {
            in_array($status, [401, 403], true) => new DomainException('AI_CREDENTIALS_REJECTED', $message, 503),
            $status === 429 => new DomainException('AI_RATE_LIMITED', $message, 503),
            $status === 404 => new DomainException('AI_MODEL_UNAVAILABLE', $message, 503),
            default => new DomainException('AI_PROVIDER_ERROR', $message, 503),
        };
    }
}
