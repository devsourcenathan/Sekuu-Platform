<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

use App\Platform\Events\DomainEvent;
use App\Platform\Exceptions\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\AI\Application\Accounts\ResolveAccount;
use Modules\AI\Application\Accounts\VerifyAccount;
use Modules\AI\Application\Models\ModelDefinition;
use Modules\AI\Application\Tasks\TaskDefinition;
use Modules\AI\Application\Tasks\TaskRegistry;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Domain\Models\AiContent;
use Modules\AI\Domain\Models\AiGeneration;
use Modules\AI\Infrastructure\Drivers\DriverRegistry;
use Modules\AI\Infrastructure\Drivers\GenerationRequest;
use Modules\AI\Infrastructure\Drivers\GenerationResult;

/**
 * Exécuter une tâche.
 *
 * ## L'ordre des gestes, et pourquoi il est celui-là
 *
 *  1. la tâche existe, et cet acteur peut la demander ;
 *  2. l'idempotence — une clé déjà vue rend la génération d'origine ;
 *  3. le compte, donc **qui paie** ;
 *  4. les bornes de dépense, qui dépendent de la réponse précédente ;
 *  5. l'entrée tient dans ce que la tâche accepte ;
 *  6. les tentatives ;
 *  7. le registre, dans tous les cas.
 *
 * Les bornes viennent après la résolution parce qu'un compte de client ne
 * consomme pas nos crédits : vérifier le quota avant de savoir à qui appartient
 * la clé refuserait un appel que personne ne nous facture.
 *
 * @see docs/03-services/ai/03-api.md
 * @see docs/04-decisions/adr-0016-ai-spend-and-privacy.md
 */
final class RunTask
{
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly ResolveAccount $accounts,
        private readonly DriverRegistry $drivers,
        private readonly SpendLedger $ledger,
        private readonly ComposePrompt $prompts,
    ) {}

    public function handle(TaskRequest $request): AiGeneration
    {
        $task = $this->tasks->get($request->task);

        if (! $request->actor->mayRun($task->name)) {
            throw DomainException::forbidden(
                'AI_TASK_OUT_OF_SCOPE',
                __('ai::messages.task_out_of_scope', ['task' => $task->name]),
            );
        }

        $prompt = $this->prompts->handle($request->inputs);

        $deja = $this->alreadyRun($request);

        if ($deja !== null) {
            return $deja;
        }

        $comptes = $this->accounts->handle($task->name, $request->actor, $request->account);

        if ($comptes === []) {
            throw DomainException::conflict(
                'MODEL_NOT_AVAILABLE',
                __('ai::messages.no_account_for_model', ['model' => $task->model]),
            );
        }

        /*
         * Le premier compte suffit à décider des bornes : la liste est
         * homogène en propriété. Les rangs 1 à 3 n'en rendent qu'un, et le
         * rang 4 ne rend que des comptes de la plateforme.
         *
         * Un compte de tiers ne bascule jamais vers un des nôtres — ce serait
         * faire payer autrui, silencieusement.
         */
        $this->ledger->assertMayRun($comptes[0], $request->actor->organizationId);
        $this->assertInputFits($task, $prompt);

        $generation = $this->open($task, $request, $prompt);

        return $this->attempt($generation, $task, $request, $prompt, $comptes);
    }

    /**
     * Une clé d'idempotence déjà vue rend la génération d'origine.
     *
     * Elle ne relance rien, même si la première a échoué : réessayer est une
     * **décision de coût**, et la prendre à la place de l'appelant lui ferait
     * payer deux fois pour ce qu'il croit être un appel.
     */
    private function alreadyRun(TaskRequest $request): ?AiGeneration
    {
        if ($request->idempotencyKey === null) {
            return null;
        }

        return AiGeneration::query()
            ->where('organization_id', $request->actor->organizationId)
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();
    }

    /**
     * L'entrée tient-elle dans ce que la tâche accepte ?
     *
     * ## Une estimation, et elle est annoncée comme telle
     *
     * Nous n'avons pas le tokeniseur du fournisseur, et il diffère d'un modèle à
     * l'autre. Quatre octets par jeton est une approximation grossière, prudente
     * sur du texte latin et **optimiste** sur d'autres écritures.
     *
     * Ce contrôle n'existe donc pas pour être exact : il existe pour arrêter ce
     * qui est manifestement hors bornes — un fichier collé dans un champ de
     * saisie — avant d'en payer les jetons. Le fournisseur refusera le reste,
     * plus précisément que nous.
     */
    private function assertInputFits(TaskDefinition $task, string $prompt): void
    {
        $estimation = (int) ceil(strlen($prompt) / 4);

        if ($estimation > $task->maxInputTokens) {
            throw DomainException::unprocessable(
                'CONTEXT_LENGTH_EXCEEDED',
                __('ai::messages.context_too_long', ['max' => $task->maxInputTokens]),
            );
        }
    }

    /**
     * La ligne est ouverte **avant** l'appel.
     *
     * Elle réserve la clé d'idempotence et laisse une trace si le processus
     * meurt entre l'envoi et la réponse — sans quoi une génération payée chez le
     * fournisseur n'existerait nulle part chez nous.
     */
    private function open(TaskDefinition $task, TaskRequest $request, string $prompt): AiGeneration
    {
        $attributs = [
            'organization_id' => $request->actor->organizationId,
            'task' => $task->name,
            'status' => AiGeneration::RUNNING,

            /*
             * L'empreinte, jamais l'entrée.
             *
             * Un registre de prompts concentrerait en clair ce que tous les
             * produits ont de plus sensible — un dossier médical, un contrat —
             * et grossirait sans limite.
             */
            'input_hash' => AiGeneration::hash($prompt),

            'requested_by' => $request->actor->id,
            'requested_via' => $request->actor->type,
            'idempotency_key' => $request->idempotencyKey,
            'started_at' => now(),
        ];

        try {
            return AiGeneration::query()->create($attributs);
        } catch (QueryException $e) {
            /*
             * Deux requêtes concurrentes portant la même clé : l'index unique
             * en refuse une. Celle qui perd lit la ligne de l'autre plutôt que
             * de lever — c'est exactement ce que l'appelant a demandé en
             * fournissant une clé.
             */
            $gagnante = $this->alreadyRun($request);

            if ($gagnante === null) {
                throw $e;
            }

            return $gagnante;
        }
    }

    /**
     * La chaîne des tentatives : chaque modèle, sur chaque compte qui le sert.
     *
     * **Par modèle d'abord.** La tâche a nommé son modèle préféré ; passer à son
     * repli est une dégradation de qualité, changer de compte n'en est pas une.
     * On épuise donc les comptes du premier modèle avant de descendre au second.
     *
     * @param  list<AiAccount>  $comptes
     */
    private function attempt(
        AiGeneration $generation,
        TaskDefinition $task,
        TaskRequest $request,
        string $prompt,
        array $comptes,
    ): AiGeneration {
        $derniere = null;
        $essais = 0;

        foreach ($this->tasks->modelsFor($task) as $model) {
            if ($model->isDeprecated()) {
                // Journalisé, pas refusé : c'est ainsi qu'on voit qui utilise
                // encore un modèle avant de le retirer.
                Log::warning('ai: modèle déprécié encore en service', [
                    'model' => $model->id,
                    'task' => $task->name,
                ]);
            }

            foreach ($comptes as $compte) {
                if (! $this->drivers->for($compte)->serves($compte, $model->id)) {
                    continue;
                }

                $essais++;

                try {
                    $resultat = $this->generate($compte, $model, $task, $prompt, $request);

                    return $this->succeed($generation, $compte, $model, $resultat, $task, $prompt, $essais);
                } catch (DomainException $e) {
                    $derniere = $e;
                    $this->reactTo($e, $compte);

                    if (! $this->mayTryElsewhere($e)) {
                        return $this->fail($generation, $compte, $model, $e, $essais);
                    }
                }
            }
        }

        return $this->fail(
            $generation,
            $comptes[0],
            null,
            $derniere ?? DomainException::conflict('MODEL_NOT_AVAILABLE', __('ai::messages.no_account_for_model', ['model' => $task->model])),
            $essais,
        );
    }

    /**
     * Un appel, et la validation de sa forme.
     *
     * Une sortie hors schéma est réessayée **une fois, sur le même compte et le
     * même modèle** : c'est un aléa d'échantillonnage, pas une panne. Aller
     * ailleurs paierait un second fournisseur pour un défaut qui n'est pas le
     * sien.
     */
    private function generate(
        AiAccount $compte,
        ModelDefinition $model,
        TaskDefinition $task,
        string $prompt,
        TaskRequest $request,
    ): GenerationResult {
        $pilote = $this->drivers->for($compte);

        $demande = new GenerationRequest(
            model: $model->id,
            prompt: $prompt,
            instructions: $task->instructions,
            maxOutputTokens: $task->maxOutputTokens,
            temperature: $task->temperature,
            json: $task->producesJson(),
            history: $task->acceptsHistory ? $request->history : [],
        );

        $resultat = $pilote->generate($compte, $demande);

        if (! $task->producesJson() || $this->isJson($resultat->output)) {
            return $resultat;
        }

        $second = $pilote->generate($compte, $demande);

        if ($this->isJson($second->output)) {
            return $second;
        }

        /*
         * L'échec porte les jetons des **deux** appels : ils ont été consommés,
         * et les cacher reviendrait à s'offrir les échecs.
         */
        throw new DomainException(
            'AI_OUTPUT_INVALID',
            __('ai::messages.output_invalid'),
            502,
            [
                'input_tokens' => $resultat->inputTokens + $second->inputTokens,
                'output_tokens' => $resultat->outputTokens + $second->outputTokens,
            ],
        );
    }

    private function isJson(string $sortie): bool
    {
        json_decode($sortie, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * **La règle de bascule, et elle est étroite.**
     *
     * On ne réessaie ailleurs que si la requête n'a jamais atteint le modèle.
     * Passé le premier jeton, les jetons sont facturés qu'on obtienne une
     * réponse ou non : réessayer ailleurs paie deux fois et rend une réponse
     * différente de celle qui arrivait peut-être.
     *
     * C'est l'ADR-0008 transposée mot pour mot — *l'incertitude compte comme un
     * appel abouti* — et c'est pourquoi la liste est une **liste blanche** : un
     * code inconnu ne bascule pas.
     */
    private function mayTryElsewhere(DomainException $e): bool
    {
        return in_array($e->errorCode, [
            // Rien n'a été atteint : DNS, connexion refusée, certificat.
            'AI_PROVIDER_UNREACHABLE',

            // Le fournisseur a répondu « pas maintenant », sans rien produire.
            'AI_RATE_LIMITED',

            // La clé est refusée : aucun jeton n'a été consommé, et ce compte
            // ne servira pas davantage le suivant.
            'AI_CREDENTIALS_REJECTED',

            // Ce compte ne sert pas ce modèle. Un autre peut-être.
            'AI_MODEL_UNAVAILABLE',

            // Le crédit du compte est épuisé chez le fournisseur : rien n'a été
            // produit, et un autre compte a de l'argent.
            'AI_CREDIT_EXHAUSTED',

            // 5xx du fournisseur. Le cas le plus discutable de la liste : un 500
            // rendu après le début de la génération aura été facturé. Il y
            // figure parce que la grande majorité des 5xx sont rendus par la
            // passerelle, avant traitement — et parce que le plafond absolu
            // borne le coût de s'être trompé.
            'AI_PROVIDER_ERROR',
        ], true);

        /*
         * Ce qui n'y est **pas**, et ne doit pas y entrer :
         *
         *  - `AI_PROVIDER_TIMEOUT` — la requête est partie, le modèle produit
         *    peut-être encore ;
         *  - `AI_OUTPUT_INVALID` — le modèle a répondu, et a été payé ;
         *  - `CONTENT_FLAGGED` — un refus de modération contourné en changeant
         *    de fournisseur serait un contournement réussi.
         */
    }

    /**
     * Une clé refusée ou un crédit épuisé sortent le compte du service, tout de
     * suite.
     *
     * Attendre l'épreuve de la nuit ferait échouer chaque appel jusque-là, un
     * par un, chez le même compte. Un débit trop rapide, lui, ne change rien :
     * il se résout seul en quelques secondes.
     */
    private function reactTo(DomainException $e, AiAccount $compte): void
    {
        $raison = match ($e->errorCode) {
            'AI_CREDENTIALS_REJECTED' => VerifyAccount::CREDENTIALS_REJECTED,
            'AI_CREDIT_EXHAUSTED' => VerifyAccount::QUOTA_EXHAUSTED,
            default => null,
        };

        if ($raison === null || $compte->status !== AiAccount::ACTIVE) {
            return;
        }

        $compte->forceFill([
            'status' => AiAccount::UNVERIFIED,
            'verification_reason' => $raison,
            'verification_error' => mb_substr($e->getMessage(), 0, 2000),
        ])->save();

        Event::dispatch(new DomainEvent('ai.account.unverified', [
            'account_id' => (string) $compte->id,
            'slug' => (string) $compte->slug,
            'reason' => $raison,
            'since' => now()->toIso8601String(),
        ]));
    }

    private function succeed(
        AiGeneration $generation,
        AiAccount $compte,
        ModelDefinition $model,
        GenerationResult $resultat,
        TaskDefinition $task,
        string $prompt,
        int $essais,
    ): AiGeneration {
        $cout = $model->costMicros($resultat->inputTokens, $resultat->outputTokens);

        $generation->forceFill([
            'status' => AiGeneration::SUCCEEDED,
            'account_id' => $compte->id,
            'provider' => $compte->preset ?? $compte->driver,
            'model' => $model->id,
            'input_tokens' => $resultat->inputTokens,
            'output_tokens' => $resultat->outputTokens,
            'cost_micros' => $cout,

            // Sur le compte d'un tiers, notre calcul suit les prix publics ; son
            // tarif négocié, son engagement de volume ou sa région donnent autre
            // chose.
            'cost_estimated' => $compte->costIsEstimated(),

            'latency_ms' => $resultat->latencyMs,
            'attempts' => $essais,
            'retain_until' => $task->retainDays === null ? null : now()->addDays($task->retainDays),
            'completed_at' => now(),
        ])->save();

        $this->ledger->record($generation->organization_id, $compte, $cout);
        $this->retain($generation, $task, $prompt, $resultat->output);

        Event::dispatch(new DomainEvent('ai.generation.succeeded', [
            'generation_id' => (string) $generation->id,
            'organization_id' => $generation->organization_id,
            'task' => $task->name,
            'model' => $model->id,
            'cost_micros' => $cout,
        ]));

        return $generation->refresh();
    }

    /**
     * L'échec est écrit, **et son coût aussi**.
     *
     * Un modèle qui a produit une réponse hors schéma a consommé des jetons.
     * Une ligne d'échec à coût nul donnerait un total qui ne correspond pas à
     * la facture du fournisseur, et l'écart ne se verrait qu'en fin de mois.
     */
    private function fail(
        AiGeneration $generation,
        AiAccount $compte,
        ?ModelDefinition $model,
        DomainException $e,
        int $essais,
    ): AiGeneration {
        $entrants = (int) ($e->details['input_tokens'] ?? 0);
        $sortants = (int) ($e->details['output_tokens'] ?? 0);
        $cout = $model !== null && ($entrants > 0 || $sortants > 0)
            ? $model->costMicros($entrants, $sortants)
            : null;

        $generation->forceFill([
            'status' => AiGeneration::FAILED,
            'account_id' => $compte->id,
            'provider' => $compte->preset ?? $compte->driver,
            'model' => $model?->id,
            'input_tokens' => $entrants > 0 ? $entrants : null,
            'output_tokens' => $sortants > 0 ? $sortants : null,
            'cost_micros' => $cout,
            'cost_estimated' => $compte->costIsEstimated(),
            'attempts' => $essais,
            'failure_code' => $e->errorCode,

            // Le message du fournisseur reste en base, jamais publié : il peut
            // porter un identifiant d'organisation ou un nom de déploiement.
            'failure_reason' => mb_substr($e->getMessage(), 0, 2000),

            'completed_at' => now(),
        ])->save();

        if ($cout !== null && $cout > 0) {
            $this->ledger->record($generation->organization_id, $compte, $cout);
        }

        Event::dispatch(new DomainEvent('ai.generation.failed', [
            'generation_id' => (string) $generation->id,
            'organization_id' => $generation->organization_id,
            'task' => (string) $generation->task,
            'failure_code' => $e->errorCode,
            'attempts' => $essais,
        ]));

        return $generation->refresh();
    }

    /**
     * La sortie survit à l'appel ; l'entrée, non.
     *
     * ## Deux durées, et deux raisons différentes
     *
     * La **sortie** est écrite dans tous les cas, sur une courte fenêtre : sans
     * elle, un sondage `GET /ai/tasks/{id}` n'aurait rien à lire, et une clé
     * d'idempotence rejouée ne rendrait que des métriques. Elle est effacée à la
     * première lecture — un produit doit écrire ce qu'il reçoit, puisqu'il est
     * le seul à savoir où cela a sa place.
     *
     * L'**entrée** n'est gardée que si la tâche le déclare. Le défaut est de ne
     * rien garder, et il n'est jamais implicite dans l'autre sens : conserver se
     * déclare.
     */
    private function retain(AiGeneration $generation, TaskDefinition $task, string $prompt, string $output): void
    {
        AiContent::query()->create([
            'generation_id' => $generation->id,
            'input' => $task->retainDays === null ? null : $prompt,
            'output' => $output,
            'expires_at' => $task->retainDays === null
                ? now()->addHours((int) config('ai.output_window_hours', 24))
                : now()->addDays($task->retainDays),
        ]);
    }
}
