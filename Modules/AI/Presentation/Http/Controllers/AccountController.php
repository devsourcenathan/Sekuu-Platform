<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\AI\Application\Accounts\RegisterAccount;
use Modules\AI\Application\Accounts\VerifyAccount;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Domain\Models\AiGeneration;
use Modules\AI\Presentation\Http\Concerns\ResolvesAiActor;

/**
 * Administrer **ses** comptes.
 *
 * ## Aucune de ces routes ne touche un compte de la plateforme
 *
 * Les nôtres portent nos identifiants et servent toutes les organisations : les
 * exposer reviendrait à confier cette infrastructure à qui détient un jeton
 * d'administration. Une clé de stockage fuitée se lit ; une clé d'IA fuitée
 * **se dépense**.
 *
 * Ils se posent par `ai:account`, à la main, ou par l'environnement là où il n'y
 * a pas de shell.
 *
 * ## Il n'y a pas de `DELETE`
 *
 * Un compte qui porte des générations ne se supprime pas : le registre dit qui a
 * payé quoi, et la ligne disparue, il ne le dirait plus. On met en pause.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class AccountController
{
    use ResolvesAiActor;

    public function __construct(
        private readonly RegisterAccount $register,
        private readonly VerifyAccount $verifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->callerOrganizationId($request, self::ACCOUNTS);

        $accounts = AiAccount::query()
            ->where('environment', app()->environment())
            ->where(function ($query) use ($organizationId): void {
                // Les comptes de la plateforme servent tout le monde ; ceux d'un
                // tiers ne servent que lui.
                $query->whereNull('owner_organization_id')->orWhere('owner_organization_id', $organizationId);
            })
            ->orderBy('priority')
            ->orderBy('slug')
            ->get();

        return ApiResponse::success($accounts->map(fn (AiAccount $a): array => $this->present($a))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'preset' => ['sometimes', 'nullable', 'string', 'max:32'],
            'driver' => ['sometimes', 'string', 'max:32'],
            'config' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'array'],
            'models' => ['sometimes', 'array'],
            'models.*' => ['string', 'max:64'],
            'spend_cap_micros' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'environment' => ['required', 'string', 'in:production,local,testing,staging'],
        ]);

        $account = $this->register->handle(
            slug: $validated['slug'],
            preset: $validated['preset'] ?? null,
            driver: $validated['driver'] ?? null,
            config: $validated['config'] ?? [],
            credentials: $validated['credentials'] ?? [],
            models: array_values($validated['models'] ?? []),
            environment: $validated['environment'],
            organizationId: $this->callerOrganizationId($request, self::ACCOUNTS),
            spendCapMicros: $validated['spend_cap_micros'] ?? null,
        );

        // `201` même si l'épreuve a échoué : le compte **existe**, il ne sert
        // simplement pas encore. Rendre une erreur obligerait le client à le
        // recréer, et laisserait une ligne orpheline à chaque tentative.
        return ApiResponse::created($this->present($account));
    }

    public function verify(Request $request, string $accountId): JsonResponse
    {
        $account = $this->find($request, $accountId);

        $this->verifier->handle($account);

        return ApiResponse::success($this->present($account->refresh()));
    }

    /**
     * Rotation : l'épreuve porte sur la nouvelle clé **avant** que l'ancienne
     * soit abandonnée.
     */
    public function credentials(Request $request, string $accountId): JsonResponse
    {
        $validated = $request->validate(['credentials' => ['required', 'array']]);

        $account = $this->register->rotate($this->find($request, $accountId), $validated['credentials']);

        return ApiResponse::success($this->present($account));
    }

    public function update(Request $request, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,paused,disabled'],
            'spend_cap_micros' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $account = $this->find($request, $accountId);

        /*
         * On ne rend pas `active` un compte qui n'a jamais rien prouvé : ce
         * serait contourner l'épreuve par la porte de service.
         */
        if (($validated['status'] ?? null) === AiAccount::ACTIVE && $account->verified_at === null) {
            throw DomainException::conflict(
                'AI_ACCOUNT_UNVERIFIED',
                __('ai::messages.activate_unverified'),
            );
        }

        $account->forceFill(Arr::only($validated, ['status', 'spend_cap_micros']))->save();

        return ApiResponse::success($this->present($account));
    }

    private function find(Request $request, string $id): AiAccount
    {
        $organizationId = $this->callerOrganizationId($request, self::ACCOUNTS);
        $account = AiAccount::query()->find($id);

        // Un compte de la plateforme rend la même erreur qu'un compte
        // inexistant : il ne s'administre pas depuis l'API, et le dire
        // autrement révélerait qu'il existe.
        if ($account === null || $account->owner_organization_id !== $organizationId) {
            throw DomainException::notFound('AI_ACCOUNT_NOT_FOUND', __('ai::messages.account_not_found'));
        }

        return $account;
    }

    /**
     * Les identifiants ne sortent jamais — pas même pour celui qui les a
     * déposés. Une empreinte suffit à reconnaître une clé ; elle ne suffit
     * jamais à s'en servir.
     *
     * @return array<string, mixed>
     */
    private function present(AiAccount $account): array
    {
        return [
            'id' => (string) $account->id,
            'slug' => $account->slug,
            'driver' => $account->driver,
            'preset' => $account->preset,
            'config' => Arr::except((array) $account->config, ['auth']),
            'credential_fingerprint' => $account->credentialFingerprint(),
            'models' => $account->models,
            'environment' => $account->environment,
            'status' => $account->status,
            'spend_cap_micros' => $account->spend_cap_micros,
            'owned_by_platform' => $account->belongsToPlatform(),
            'verified_at' => $account->verified_at?->toIso8601String(),
            'verification_reason' => $account->verification_reason,
            'generations' => AiGeneration::query()->where('account_id', $account->id)->count(),
        ];
    }
}
