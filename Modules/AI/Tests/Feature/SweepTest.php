<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AI\Domain\Models\AiContent;
use Modules\AI\Domain\Models\AiGeneration;
use Tests\TestCase;

/**
 * Le balayage : ce qui a expiré, et ce qui n'a jamais conclu.
 *
 * @see docs/03-services/ai/02-data-model.md
 */
final class SweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **L'effacement n'est pas optionnel.**
     *
     * Une durée de conservation qui ne serait pas appliquée est une promesse
     * fausse — et c'est le genre de promesse qu'on découvre fausse lors d'un
     * audit, pas avant.
     */
    public function test_expired_content_is_actually_erased(): void
    {
        $expired = $this->generation(AiGeneration::SUCCEEDED);
        $living = $this->generation(AiGeneration::SUCCEEDED);

        $this->content($expired, now()->subMinute());
        $this->content($living, now()->addDay());

        $this->artisan('ai:sweep')->assertSuccessful();

        $this->assertNull(AiContent::query()->find($expired->id));
        $this->assertNotNull(AiContent::query()->find($living->id));
    }

    public function test_a_dry_run_erases_nothing(): void
    {
        $generation = $this->generation(AiGeneration::SUCCEEDED);
        $this->content($generation, now()->subMinute());

        $this->artisan('ai:sweep', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(AiContent::query()->find($generation->id));
    }

    /**
     * **Le test qui compte de ce fichier.**
     *
     * `RunTaskJob::failed()` couvre un travail qui échoue ; il ne couvre pas un
     * travailleur tué net — mémoire épuisée, conteneur redémarré. La ligne reste
     * alors `queued` pour toujours, et l'appelant sonde indéfiniment quelque
     * chose qui ne bougera plus.
     */
    public function test_a_generation_nobody_will_resume_is_settled(): void
    {
        $abandoned = $this->generation(AiGeneration::QUEUED, now()->subHours(3));
        $running = $this->generation(AiGeneration::RUNNING, now()->subHours(3));
        $recent = $this->generation(AiGeneration::QUEUED, now()->subMinutes(5));

        $this->artisan('ai:sweep')->assertSuccessful();

        $this->assertSame(AiGeneration::FAILED, $abandoned->refresh()->status);
        $this->assertSame('AI_ABANDONED', $abandoned->failure_code);
        $this->assertNotNull($abandoned->completed_at);

        $this->assertSame(AiGeneration::FAILED, $running->refresh()->status);

        // Une génération de cinq minutes est peut-être simplement en file.
        $this->assertSame(AiGeneration::QUEUED, $recent->refresh()->status);
    }

    /**
     * Le coût **n'est pas mis à zéro** : la requête est peut-être partie, et a
     * peut-être été facturée. Écrire zéro donnerait un total qui ne correspond
     * pas à la facture du fournisseur, et l'écart ne se verrait qu'en fin de
     * mois.
     */
    public function test_an_abandoned_generation_keeps_an_unknown_cost(): void
    {
        $generation = $this->generation(AiGeneration::RUNNING, now()->subHours(3));

        $this->artisan('ai:sweep')->assertSuccessful();

        $this->assertNull($generation->refresh()->cost_micros);
    }

    /**
     * Une génération conclue ne se réécrit pas : le balayage ne connaît que
     * `queued` et `running`.
     */
    public function test_a_settled_generation_is_left_alone(): void
    {
        $succeeded = $this->generation(AiGeneration::SUCCEEDED, now()->subDays(30));
        $cancelled = $this->generation(AiGeneration::CANCELLED, now()->subDays(30));

        $this->artisan('ai:sweep')->assertSuccessful();

        $this->assertSame(AiGeneration::SUCCEEDED, $succeeded->refresh()->status);
        $this->assertSame(AiGeneration::CANCELLED, $cancelled->refresh()->status);
    }

    private function content(AiGeneration $generation, $expiresAt): void
    {
        AiContent::query()->create([
            'generation_id' => $generation->id,
            'input' => null,
            'output' => 'une sortie',
            'expires_at' => $expiresAt,
        ]);
    }

    private function generation(string $status, $createdAt = null): AiGeneration
    {
        $generation = AiGeneration::query()->create([
            'organization_id' => (string) Str::uuid(),
            'task' => 'prompt',
            'status' => $status,
            'input_hash' => AiGeneration::hash('bonjour'),
        ]);

        if ($createdAt !== null) {
            $generation->forceFill(['created_at' => $createdAt])->save();
        }

        return $generation->refresh();
    }
}
