<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Infrastructure\Drivers\DriverRegistry;
use Modules\AI\Infrastructure\Drivers\GenerationRequest;
use Tests\TestCase;

/**
 * Les deux vrais pilotes, **exécutés**.
 *
 * ## Pourquoi ce fichier existe avant tout le reste
 *
 * Le pilote S3 de Storage avait été écrit, documenté et recommandé sans jamais
 * être instancié : toute la suite s'appuyait sur le pilote local, seul
 * utilisable hors ligne. Son adaptateur Flysystem manquait des dépendances, et
 * 561 tests ne l'ont pas vu. Le défaut est apparu au premier démarrage en
 * production, classé `unreachable` — c'est-à-dire au mauvais endroit.
 *
 * Ici les deux pilotes sont construits et appelés contre `Http::fake`. Ça ne
 * prouve pas qu'une clé fonctionne ; ça prouve que le code s'exécute, que les
 * en-têtes sont les bons, que le format des messages est celui du fournisseur,
 * et que les jetons sont lus là où ils se trouvent.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class DriverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Anthropic met les instructions dans un champ `system` **séparé**, pas
     * dans les messages. Un pilote qui les y glisserait produirait une réponse
     * plausible et subtilement différente.
     */
    public function test_the_anthropic_driver_speaks_its_own_protocol(): void
    {
        Http::fake([
            '*/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'bonjour']],
                'usage' => ['input_tokens' => 42, 'output_tokens' => 7],
            ]),
        ]);

        $resultat = app(DriverRegistry::class)->get('anthropic')->generate(
            $this->account('anthropic', 'https://api.anthropic.com'),
            new GenerationRequest(
                model: 'claude-sonnet-4-6',
                prompt: 'salut',
                instructions: 'Sois bref.',
                maxOutputTokens: 100,
                temperature: 0.2,
                json: false,
            ),
        );

        $this->assertSame('bonjour', $resultat->output);
        $this->assertSame(42, $resultat->inputTokens);
        $this->assertSame(7, $resultat->outputTokens);

        Http::assertSent(function ($request): bool {
            $corps = $request->data();

            return $request->hasHeader('x-api-key')
                && $request->hasHeader('anthropic-version')
                && ! $request->hasHeader('Authorization')

                // Les instructions à part, et un seul message utilisateur.
                && $corps['system'] === 'Sois bref.'
                && count($corps['messages']) === 1
                && $corps['messages'][0]['role'] === 'user';
        });
    }

    /**
     * Une réponse Anthropic est une **liste de blocs**. Concaténer autre chose
     * que le texte produirait une sortie silencieusement fausse.
     */
    public function test_the_anthropic_driver_keeps_only_text_blocks(): void
    {
        Http::fake([
            '*/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'la réponse'],
                    ['type' => 'tool_use', 'name' => 'chercher', 'input' => ['q' => 'x']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 3],
            ]),
        ]);

        $resultat = app(DriverRegistry::class)->get('anthropic')->generate(
            $this->account('anthropic', 'https://api.anthropic.com'),
            $this->request(),
        );

        $this->assertSame('la réponse', $resultat->output);
    }

    /**
     * Le protocole d'OpenAI, celui que Google, DeepSeek, Mistral et Groq
     * parlent à l'identique : les instructions sont un message de rôle
     * `system`.
     */
    public function test_the_openai_driver_puts_instructions_in_the_messages(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'salut']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
            ]),
        ]);

        $resultat = app(DriverRegistry::class)->get('openai')->generate(
            $this->account('openai', 'https://api.deepseek.com/v1'),
            new GenerationRequest(
                model: 'deepseek-chat',
                prompt: 'bonjour',
                instructions: 'Sois bref.',
                maxOutputTokens: 100,
                temperature: 0.2,
                json: true,
            ),
        );

        $this->assertSame('salut', $resultat->output);
        $this->assertSame(12, $resultat->inputTokens);

        Http::assertSent(function ($request): bool {
            $corps = $request->data();

            return $request->hasHeader('Authorization')
                && ! $request->hasHeader('x-api-key')
                && $corps['messages'][0]['role'] === 'system'
                && $corps['messages'][1]['role'] === 'user'
                && $corps['response_format']['type'] === 'json_object';
        });
    }

    /**
     * Azure parle le même protocole, avec un autre en-tête. Deux paramètres,
     * toujours pas de pilote de plus — c'est ce que vaut le choix de nommer un
     * pilote d'après un protocole.
     */
    public function test_azure_is_the_same_driver_with_another_header(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])]);

        $compte = $this->account('openai', 'https://exemple.openai.azure.com');
        $compte->forceFill(['config' => ['base_url' => 'https://exemple.openai.azure.com', 'auth' => 'api-key']])->save();

        app(DriverRegistry::class)->get('openai')->generate($compte->fresh(), $this->request('deepseek-chat'));

        Http::assertSent(fn ($request): bool => $request->hasHeader('api-key') && ! $request->hasHeader('Authorization'));
    }

    /**
     * Les erreurs sont distinguées là où le statut le permet sans ambiguïté.
     * La suite — bascule ou non — se décide au-dessus, et elle en dépend.
     */
    public function test_provider_failures_are_classified(): void
    {
        /*
         * Une **séquence**, et non un `Http::fake` par tour de boucle.
         *
         * Les doublures s'empilent au lieu de se remplacer : la première
         * enregistrée répondrait à tous les appels suivants, et le test
         * passerait en ne vérifiant qu'un seul cas.
         */
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(['error' => 'x'], 401)
            ->push(['error' => 'x'], 429)
            ->push(['error' => 'x'], 404)
            ->push(['error' => 'x'], 500)]);

        $cas = [
            401 => 'AI_CREDENTIALS_REJECTED',
            429 => 'AI_RATE_LIMITED',
            404 => 'AI_MODEL_UNAVAILABLE',
            500 => 'AI_PROVIDER_ERROR',
        ];

        foreach ($cas as $statut => $attendu) {
            try {
                app(DriverRegistry::class)->get('openai')->generate(
                    $this->account('openai', 'https://api.deepseek.com/v1'),
                    $this->request('deepseek-chat'),
                );

                $this->fail("Le statut {$statut} aurait dû lever.");
            } catch (DomainException $e) {
                $this->assertSame($attendu, $e->errorCode, "Statut {$statut}");
            }
        }
    }

    /**
     * L'épreuve **consomme réellement des jetons**. Une épreuve qui listerait
     * les modèles ne prouverait pas ce qui compte : un compte peut lister sans
     * avoir de crédit.
     */
    public function test_the_probe_actually_generates(): void
    {
        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '.']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        app(DriverRegistry::class)->get('anthropic')->probe(
            $this->account('anthropic', 'https://api.anthropic.com'),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/messages')
            && $request->data()['max_tokens'] === 1);
    }

    /**
     * Les identifiants ne quittent jamais le modèle, même sérialisé.
     */
    public function test_credentials_never_leave_the_model(): void
    {
        $compte = $this->account('anthropic', 'https://api.anthropic.com');

        $this->assertArrayNotHasKey('credentials', $compte->fresh()->toArray());
        $this->assertStringContainsString('MPLE', (string) $compte->credentialFingerprint());
        $this->assertStringNotContainsString('sk-ant-EXAMPLE', (string) $compte->credentialFingerprint());
    }

    private function request(string $model = 'claude-sonnet-4-6'): GenerationRequest
    {
        return new GenerationRequest(
            model: $model,
            prompt: 'bonjour',
            instructions: null,
            maxOutputTokens: 100,
            temperature: 0.2,
            json: false,
        );
    }

    private function account(string $driver, string $baseUrl): AiAccount
    {
        return AiAccount::query()->create([
            'slug' => 'test-'.$driver.'-'.uniqid(),
            'driver' => $driver,
            'config' => ['base_url' => $baseUrl],
            'credentials' => ['api_key' => 'sk-ant-EXAMPLE'],
            'models' => ['claude-sonnet-4-6', 'deepseek-chat'],
            'environment' => app()->environment(),
            'status' => AiAccount::ACTIVE,
            'verified_at' => now(),
        ]);
    }
}
