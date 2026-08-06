<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use App\Platform\Contracts\AiActor;
use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\AI\Application\Generation\ReadGeneration;
use Modules\AI\Application\Generation\RunTask;
use Modules\AI\Application\Generation\RunTaskJob;
use Modules\AI\Application\Generation\SubmitTask;
use Modules\AI\Application\Generation\TaskRequest;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Domain\Models\AiContent;
use Modules\AI\Domain\Models\AiGeneration;
use Modules\AI\Infrastructure\Drivers\FakeDriver;
use Tests\TestCase;

/**
 * L'asynchrone : ce qui est décidé tout de suite, et ce qui attend.
 *
 * @see docs/03-services/ai/03-api.md
 */
final class AsyncGenerationTest extends TestCase
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
     * Le choix appartient à la **tâche**, jamais à l'appelant : une tâche
     * déclarée courte peut s'allonger, et le changement ne sera pas annoncé.
     */
    public function test_the_task_decides_synchronous_or_queued(): void
    {
        Queue::fake();
        $this->account('principal');

        // `prompt-fast` est déclarée synchrone, `prompt` ne l'est pas.
        $this->assertSame(AiGeneration::SUCCEEDED, $this->submit('prompt-fast')->status);
        $this->assertSame(AiGeneration::QUEUED, $this->submit('prompt')->status);

        Queue::assertPushed(RunTaskJob::class, 1);
    }

    /**
     * **Le test qui compte de ce fichier.**
     *
     * Un quota épuisé ou une tâche hors périmètre doivent être dits pendant que
     * l'appelant écoute. Les découvrir dans la file lui rendrait un `202` suivi
     * d'un échec qu'il n'apprendra que par un sondage — une erreur de sa requête
     * déguisée en panne de la nôtre.
     */
    public function test_the_guards_run_before_the_queue_not_inside_it(): void
    {
        Queue::fake();
        $this->account('principal');

        try {
            app(SubmitTask::class)->handle(new TaskRequest(
                task: 'prompt',
                actor: AiActor::apiKey((string) Str::uuid(), ['summarize'], $this->org),
                inputs: ['prompt' => 'Bonjour.'],
            ));
            $this->fail('Une tâche hors périmètre doit être refusée à la soumission.');
        } catch (DomainException $e) {
            $this->assertSame('AI_TASK_OUT_OF_SCOPE', $e->errorCode);
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, AiGeneration::query()->count(), 'Rien ne doit être ouvert si rien ne partira.');
    }

    /**
     * Une demande enfilée n'a pas commencé. L'horodater à l'ouverture rendrait
     * toute mesure de latence dépendante de la profondeur de la file.
     */
    public function test_a_queued_generation_has_not_started(): void
    {
        Queue::fake();
        $this->account('principal');

        $generation = $this->submit('prompt');

        $this->assertNull($generation->started_at);
        $this->assertNull($generation->completed_at);
    }

    public function test_the_worker_carries_the_task_through(): void
    {
        $this->account('principal');

        $generation = $this->submit('prompt');
        $generation->refresh();

        $this->assertSame(AiGeneration::SUCCEEDED, $generation->status);
        $this->assertNotNull($generation->started_at);
        $this->assertSame(1, FakeDriver::$calls);
    }

    /**
     * Ce qui a changé entre l'ouverture et l'exécution s'écrit **sur la ligne**,
     * pas dans un travail en échec : l'appelant sonde la ligne, un travail mort
     * ne lui dit rien.
     */
    public function test_a_condition_that_changed_since_opening_is_written_on_the_row(): void
    {
        Queue::fake();
        $account = $this->account('principal');

        $generation = $this->submit('prompt');

        // Le compte est mis en pause après l'ouverture.
        $account->forceFill(['status' => AiAccount::PAUSED])->save();

        (new RunTaskJob((string) $generation->id, $this->request('prompt')))->handle(app(RunTask::class));

        $generation->refresh();
        $this->assertSame(AiGeneration::FAILED, $generation->status);
        $this->assertSame('MODEL_NOT_AVAILABLE', $generation->failure_code);
    }

    /**
     * Sans ceci, la demande resterait `queued` pour toujours, et l'appelant
     * sonderait indéfiniment une ligne que plus personne ne reprendra.
     */
    public function test_a_worker_that_dies_settles_the_row(): void
    {
        Queue::fake();
        $this->account('principal');

        $generation = $this->submit('prompt');

        (new RunTaskJob((string) $generation->id, $this->request('prompt')))
            ->failed(new \RuntimeException('travailleur tué'));

        $this->assertSame(AiGeneration::FAILED, $generation->refresh()->status);
        $this->assertNotNull($generation->completed_at);
    }

    /**
     * Un travail conclu par `RunTask` en sait plus que la file : `failed()` ne
     * réécrit pas par-dessus.
     */
    public function test_a_late_failure_hook_does_not_overwrite_a_settled_row(): void
    {
        $this->account('principal');

        $generation = $this->submit('prompt');

        (new RunTaskJob((string) $generation->id, $this->request('prompt')))
            ->failed(new \RuntimeException('arrivé trop tard'));

        $this->assertSame(AiGeneration::SUCCEEDED, $generation->refresh()->status);
    }

    /**
     * **La sortie n'est lisible qu'une fois.**
     *
     * La garder ferait de cette base le dépôt de ce que tous les produits ont de
     * plus sensible. La relecture n'échoue pas pour autant : elle rend les
     * métriques sans la sortie — une erreur ferait croire à une génération
     * perdue alors qu'elle a bien eu lieu, et a été payée.
     */
    public function test_the_output_is_read_once_then_gone(): void
    {
        $this->account('principal');

        $generation = $this->submit('prompt');

        $first = app(ReadGeneration::class)->handle((string) $generation->id, $this->actor());
        $this->assertSame('réponse factice', $first['output']);

        $second = app(ReadGeneration::class)->handle((string) $generation->id, $this->actor());
        $this->assertNull($second['output']);
        $this->assertSame(AiGeneration::SUCCEEDED, $second['generation']->status);
        $this->assertSame(0, AiContent::query()->count());
    }

    /**
     * « Pas la vôtre » et « n'existe pas » rendent la même erreur : distinguer
     * les deux dirait à qui essaie des identifiants au hasard lesquels existent.
     */
    public function test_the_generation_of_another_organization_is_not_readable(): void
    {
        $this->account('principal');

        $generation = $this->submit('prompt');

        $this->expectException(DomainException::class);

        app(ReadGeneration::class)->handle(
            (string) $generation->id,
            AiActor::user((string) Str::uuid(), (string) Str::uuid()),
        );
    }

    private function submit(string $task): AiGeneration
    {
        return app(SubmitTask::class)->handle($this->request($task));
    }

    private function request(string $task): TaskRequest
    {
        return new TaskRequest(task: $task, actor: $this->actor(), inputs: ['prompt' => 'Bonjour.']);
    }

    private function actor(): AiActor
    {
        return AiActor::user('11111111-1111-4111-8111-111111111111', $this->org);
    }

    private function account(string $slug): AiAccount
    {
        return AiAccount::query()->create([
            'slug' => $slug,
            'driver' => 'fake',
            'config' => ['base_url' => 'https://faux.exemple.cm'],
            'credentials' => ['api_key' => 'x'],
            'models' => ['claude-sonnet-4-6', 'gemini-2.5-flash', 'deepseek-chat'],
            'environment' => app()->environment(),
            'status' => AiAccount::ACTIVE,
            'verified_at' => now(),
        ]);
    }
}
