<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use App\Platform\Contracts\AiActor;
use App\Platform\Contracts\BillingContract;
use App\Platform\Contracts\PlanLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\AI\Application\Generation\AnnounceOutcome;
use Modules\AI\Application\Generation\RunTask;
use Modules\AI\Application\Generation\TaskRequest;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Domain\Models\AiDelivery;
use Modules\AI\Domain\Models\AiEndpoint;
use Modules\AI\Infrastructure\Drivers\FakeDriver;
use Modules\AI\Infrastructure\External\DeliverAiEvent;
use Tests\TestCase;

/**
 * Ce que le produit apprend, et ce qu'il n'apprend pas.
 *
 * @see docs/03-services/ai/07-external-api.md
 */
final class OutboundDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private string $org;

    protected function setUp(): void
    {
        parent::setUp();

        FakeDriver::reset();
        $this->org = (string) Str::uuid();
    }

    /**
     * **Le test qui compte de ce fichier.**
     *
     * Un webhook part vers une URL déclarée par le produit, en clair sur le
     * réseau public. Y mettre le prompt ou la sortie reviendrait à publier ce
     * que l'ADR-0016 refuse de stocker.
     */
    public function test_a_delivery_never_carries_the_prompt_or_the_output(): void
    {
        Queue::fake();
        $this->endpoint();
        $this->account('principal');

        FakeDriver::$output = 'Le patient présente un diabète de type 2.';

        app(RunTask::class)->handle($this->request('Dossier 4471, antécédents familiaux.'));

        $payload = (string) json_encode(AiDelivery::query()->firstOrFail()->payload, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('4471', $payload);
        $this->assertStringNotContainsString('diabète', $payload);

        // Ce qu'il apprend : qu'une sortie l'attend, et ce qu'elle a coûté.
        $this->assertStringContainsString(AnnounceOutcome::SUCCEEDED, $payload);
        $this->assertStringContainsString('cost_micros', $payload);
    }

    public function test_a_failure_is_announced_too(): void
    {
        Queue::fake();
        $this->endpoint();
        $this->account('principal');

        FakeDriver::failOnce('AI_PROVIDER_TIMEOUT', 504);

        app(RunTask::class)->handle($this->request());

        $delivery = AiDelivery::query()->firstOrFail();

        $this->assertSame(AnnounceOutcome::FAILED, $delivery->event_type);
        $this->assertSame('AI_PROVIDER_TIMEOUT', $delivery->payload['data']['failure_code']);
    }

    /**
     * Un produit sans destination n'est pas une erreur : il lui reste le
     * sondage, qui est de toute façon la voie normale.
     */
    public function test_a_product_without_an_endpoint_is_not_an_error(): void
    {
        Queue::fake();
        $this->account('principal');

        app(RunTask::class)->handle($this->request());

        $this->assertSame(0, AiDelivery::query()->count());
    }

    /**
     * L'identifiant d'événement est **stable d'un réessai à l'autre** : c'est
     * la clé sur laquelle le produit déduplique, et une clé qui changerait à
     * chaque envoi rendrait la déduplication impossible.
     */
    public function test_the_event_id_survives_a_retry(): void
    {
        $this->endpoint();
        $delivery = $this->pendingDelivery();

        Http::fake(['*' => Http::sequence()->push([], 500)->push([], 200)]);

        try {
            (new DeliverAiEvent((string) $delivery->id))->handle();
        } catch (\Throwable) {
            // Refusée : la file réessaiera.
        }

        (new DeliverAiEvent((string) $delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame(AiDelivery::DELIVERED, $delivery->status);
        $this->assertSame(2, $delivery->attempts);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->header('X-Sekuu-Event-Id')[0] === $delivery->event_id);
    }

    /**
     * Pendant une rotation, la livraison porte **les deux** signatures. Le
     * produit change son secret quand il veut, sans qu'aucun message ne soit
     * rejeté entre-temps.
     */
    public function test_a_rotation_signs_with_both_secrets(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->forceFill([
            'previous_secret' => 'whsec_ancien',
            'previous_secret_expires_at' => now()->addHours(48),
        ])->save();

        $delivery = $this->pendingDelivery();

        Http::fake(['*' => Http::response([], 200)]);

        (new DeliverAiEvent((string) $delivery->id))->handle();

        Http::assertSent(function ($request) use ($endpoint, $delivery): bool {
            $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signatures = explode(',', $request->header('X-Sekuu-Signature')[0]);

            return count($signatures) === 2
                && $signatures[0] === 'v1='.hash_hmac('sha256', $body, (string) $endpoint->secret)
                && $signatures[1] === 'v1='.hash_hmac('sha256', $body, 'whsec_ancien');
        });
    }

    /**
     * Suspendu : la livraison reste `pending` et repartira. Elle n'est ni
     * perdue ni consommée.
     */
    public function test_a_paused_endpoint_holds_deliveries_without_losing_them(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->forceFill(['status' => AiEndpoint::PAUSED])->save();

        $delivery = $this->pendingDelivery();

        Http::fake();

        (new DeliverAiEvent((string) $delivery->id))->handle();

        $this->assertSame(AiDelivery::PENDING, $delivery->refresh()->status);
        $this->assertSame(0, $delivery->attempts);
        Http::assertNothingSent();
    }

    /**
     * **L'endpoint n'est pas désactivé** quand les réessais sont épuisés. Une
     * panne de quelques heures chez le produit transformerait sinon une
     * interruption en silence permanent.
     */
    public function test_an_exhausted_delivery_leaves_the_endpoint_alone(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->pendingDelivery();

        (new DeliverAiEvent((string) $delivery->id))->failed(new \RuntimeException('tous les essais'));

        $this->assertSame(AiDelivery::EXHAUSTED, $delivery->refresh()->status);
        $this->assertSame(AiEndpoint::ACTIVE, $endpoint->refresh()->status);
    }

    /**
     * Le seuil est annoncé **au franchissement**, une seule fois. « Au-delà de
     * 80 % » produirait un message à chaque génération jusqu'à la fin du mois,
     * et le produit apprendrait à les ignorer — celui des 100 % compris.
     */
    public function test_a_threshold_is_announced_when_crossed_and_only_then(): void
    {
        Queue::fake();
        $this->endpoint();
        $this->account('principal');

        /*
         * 775 crédits, et chaque génération en coûte 155 : `prompt-fast` passe
         * par `gemini-2.5-flash`, soit 100 jetons à 0,30 $/M plus 50 à 2,50 $/M.
         *
         * Les seuils tombent donc exactement sur la 4ᵉ (620 = 80 %) et la 5ᵉ
         * (775 = 100 %).
         */
        $this->app->bind(BillingContract::class, fn (): BillingContract => new class implements BillingContract
        {
            public function limit(string $organizationId, string $key): PlanLimit
            {
                return PlanLimit::of(775);
            }
        });

        // 155, 310, 465 : aucun seuil.
        for ($i = 0; $i < 3; $i++) {
            app(RunTask::class)->handle($this->request());
        }
        $this->assertSame(0, $this->thresholds());

        // 620 : les 80 % sont franchis.
        app(RunTask::class)->handle($this->request());
        $this->assertSame(1, $this->thresholds());

        // 775 : les 100 %. Deux annonces en tout, pas cinq.
        app(RunTask::class)->handle($this->request());
        $this->assertSame(2, $this->thresholds());
    }

    private function thresholds(): int
    {
        return AiDelivery::query()->where('event_type', AnnounceOutcome::THRESHOLD)->count();
    }

    private function pendingDelivery(): AiDelivery
    {
        Queue::fake();
        $this->account('principal');

        app(RunTask::class)->handle($this->request());

        return AiDelivery::query()->firstOrFail();
    }

    private function request(string $prompt = 'Bonjour.'): TaskRequest
    {
        return new TaskRequest(
            task: 'prompt-fast',
            actor: AiActor::user('11111111-1111-4111-8111-111111111111', $this->org),
            inputs: ['prompt' => $prompt],
        );
    }

    private function endpoint(): AiEndpoint
    {
        return AiEndpoint::query()->create([
            'organization_id' => $this->org,
            'url' => 'https://produit.test/webhooks/ai',
            'secret' => 'whsec_courant',
            'status' => AiEndpoint::ACTIVE,
        ]);
    }

    private function account(string $slug): AiAccount
    {
        return AiAccount::query()->create([
            'slug' => $slug,
            'driver' => 'fake',
            'config' => ['base_url' => 'https://faux.exemple.cm'],
            'credentials' => ['api_key' => 'x'],
            'models' => ['fake-model', 'gemini-2.5-flash', 'deepseek-chat'],
            'environment' => app()->environment(),
            'status' => AiAccount::ACTIVE,
            'verified_at' => now(),
        ]);
    }
}
