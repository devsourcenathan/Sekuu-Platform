<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Infrastructure\Drivers\DriverRegistry;
use Modules\AI\Infrastructure\Drivers\GenerationRequest;
use Modules\AI\Infrastructure\Drivers\ProviderFailure;
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

        $result = app(DriverRegistry::class)->get('anthropic')->generate(
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

        $this->assertSame('bonjour', $result->output);
        $this->assertSame(42, $result->inputTokens);
        $this->assertSame(7, $result->outputTokens);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->hasHeader('x-api-key')
                && $request->hasHeader('anthropic-version')
                && ! $request->hasHeader('Authorization')

                // Les instructions à part, et un seul message utilisateur.
                && $body['system'] === 'Sois bref.'
                && count($body['messages']) === 1
                && $body['messages'][0]['role'] === 'user';
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

        $result = app(DriverRegistry::class)->get('anthropic')->generate(
            $this->account('anthropic', 'https://api.anthropic.com'),
            $this->request(),
        );

        $this->assertSame('la réponse', $result->output);
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

        $result = app(DriverRegistry::class)->get('openai')->generate(
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

        $this->assertSame('salut', $result->output);
        $this->assertSame(12, $result->inputTokens);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->hasHeader('Authorization')
                && ! $request->hasHeader('x-api-key')
                && $body['messages'][0]['role'] === 'system'
                && $body['messages'][1]['role'] === 'user'
                && $body['response_format']['type'] === 'json_object';
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

        $account = $this->account('openai', 'https://exemple.openai.azure.com');
        $account->forceFill(['config' => ['base_url' => 'https://exemple.openai.azure.com', 'auth' => 'api-key']])->save();

        app(DriverRegistry::class)->get('openai')->generate($account->fresh(), $this->request('deepseek-chat'));

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

        $cases = [
            401 => 'AI_CREDENTIALS_REJECTED',
            429 => 'AI_RATE_LIMITED',
            404 => 'AI_MODEL_UNAVAILABLE',
            500 => 'AI_PROVIDER_ERROR',
        ];

        foreach ($cases as $status => $expected) {
            try {
                app(DriverRegistry::class)->get('openai')->generate(
                    $this->account('openai', 'https://api.deepseek.com/v1'),
                    $this->request('deepseek-chat'),
                );

                $this->fail("Le statut {$status} aurait dû lever.");
            } catch (DomainException $e) {
                $this->assertSame($expected, $e->errorCode, "Statut {$status}");
            }
        }
    }

    /**
     * **La distinction dont dépend toute la règle de bascule.**
     *
     * Laravel lève la même `ConnectionException` pour deux faits opposés : le
     * serveur n'a jamais répondu, ou le délai a expiré en attendant sa réponse.
     * Dans le premier cas rien n'est facturé et on peut aller ailleurs ; dans le
     * second les jetons sont consommés, et réessayer paie deux fois.
     *
     * Le doute penche vers « c'est arrivé » : se tromper dans ce sens coûte une
     * génération, dans l'autre il coûte de l'argent et rend une réponse
     * différente de celle qui arrivait.
     */
    public function test_a_transport_failure_says_whether_the_request_arrived(): void
    {
        $cases = [
            'cURL error 6: Could not resolve host: api.exemple.cm' => 'AI_PROVIDER_UNREACHABLE',
            'cURL error 7: Failed to connect to api.exemple.cm port 443' => 'AI_PROVIDER_UNREACHABLE',
            'cURL error 35: SSL connect error' => 'AI_PROVIDER_UNREACHABLE',

            // Le délai, et tout ce qu'on ne sait pas lire.
            'cURL error 28: Operation timed out after 120000 milliseconds' => 'AI_PROVIDER_TIMEOUT',
            'quelque chose que personne n\'a prévu' => 'AI_PROVIDER_TIMEOUT',
        ];

        foreach ($cases as $message => $expected) {
            $this->assertSame(
                $expected,
                ProviderFailure::from(new ConnectionException($message))->errorCode,
                $message,
            );
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
        $account = $this->account('anthropic', 'https://api.anthropic.com');

        $this->assertArrayNotHasKey('credentials', $account->fresh()->toArray());
        $this->assertStringContainsString('MPLE', (string) $account->credentialFingerprint());
        $this->assertStringNotContainsString('sk-ant-EXAMPLE', (string) $account->credentialFingerprint());
    }

    /**
     * **Un crédit épuisé n'est pas une clé refusée**, et les fournisseurs ne
     * disent pas la même chose pour le dire.
     *
     * Les confondre fait réessayer indéfiniment chez un compte à sec, et envoie
     * régénérer une clé qui n'a rien de cassé : l'un se résout en quelques
     * secondes, l'autre demande une carte bancaire.
     *
     * Les deux premiers cas sont des réponses **réellement observées** ; le
     * dernier vérifie qu'un `402` seul suffit, puisque tous les fournisseurs ne
     * nomment pas la chose.
     */
    public function test_an_exhausted_balance_is_told_apart_from_a_bad_key(): void
    {
        $cases = [
            // DeepSeek, observé en conditions réelles.
            [402, ['error' => ['message' => 'Insufficient Balance']], 'AI_CREDIT_EXHAUSTED'],

            // OpenAI, même fait sous un autre statut et un autre nom.
            [429, ['error' => ['code' => 'insufficient_quota']], 'AI_CREDIT_EXHAUSTED'],

            // Un fournisseur muet : le statut suffit.
            [402, ['error' => 'nope'], 'AI_CREDIT_EXHAUSTED'],

            // Et un vrai débit trop rapide reste distinct.
            [429, ['error' => ['message' => 'Rate limit reached']], 'AI_RATE_LIMITED'],
        ];

        /*
         * Une **séquence**, et non un `Http::fake` par tour de boucle.
         *
         * Les doublures s'empilent au lieu de se remplacer : la première
         * enregistrée répond à tous les appels suivants. Écrit d'abord avec un
         * `Http::fake` dans la boucle, ce test a classé « Rate limit reached »
         * en crédit épuisé — parce qu'il relisait la réponse du premier cas.
         *
         * Le piège est le même que dans `test_provider_failures_are_classified`,
         * et il s'est reproduit malgré le commentaire qui l'y décrit.
         */
        $sequence = Http::sequence();

        foreach ($cases as [$status, $body, $_]) {
            $sequence->push($body, $status);
        }

        Http::fake(['*/chat/completions' => $sequence]);

        foreach ($cases as [$status, $body, $expected]) {
            try {
                app(DriverRegistry::class)->get('openai')->generate(
                    $this->account('openai', 'https://api.deepseek.com/v1'),
                    $this->request('deepseek-chat'),
                );

                $this->fail("Le statut {$status} aurait dû lever.");
            } catch (DomainException $e) {
                $this->assertSame($expected, $e->errorCode, json_encode($body, JSON_UNESCAPED_UNICODE));
            }
        }
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
