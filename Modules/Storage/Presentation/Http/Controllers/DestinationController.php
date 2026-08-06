<?php

declare(strict_types=1);

namespace Modules\Storage\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Storage\Application\Destinations\RegisterDestination;
use Modules\Storage\Application\Destinations\VerifyDestination;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Presentation\Http\Concerns\ResolvesFileActor;

/**
 * Administrer les magasins.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class DestinationController
{
    use ResolvesFileActor;

    public function __construct(
        private readonly RegisterDestination $register,
        private readonly VerifyDestination $verifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->callerOrganizationId($request, self::DESTINATIONS);

        $destinations = Destination::query()
            ->where('environment', app()->environment())
            ->where(function ($query) use ($organizationId): void {
                // Les destinations de la plateforme servent tout le monde ;
                // celle d'un tiers ne sert que lui.
                $query->whereNull('owner_organization_id')->orWhere('owner_organization_id', $organizationId);
            })
            ->orderBy('slug')
            ->get();

        return ApiResponse::success($destinations->map(fn (Destination $d): array => $this->present($d))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'preset' => ['sometimes', 'nullable', 'string', 'max:32'],
            'driver' => ['sometimes', 'string', 'max:32'],
            'config' => ['required', 'array'],
            'credentials' => ['sometimes', 'array'],
            'environment' => ['required', 'string', 'in:production,local,testing,staging'],
        ]);

        $destination = $this->register->handle(
            slug: $validated['slug'],
            preset: $validated['preset'] ?? null,
            driver: $validated['driver'] ?? null,
            config: $validated['config'],
            credentials: $validated['credentials'] ?? [],
            environment: $validated['environment'],
            organizationId: $this->callerOrganizationId($request, self::DESTINATIONS),
        );

        // `201` même si l'épreuve a échoué : la destination **existe**, elle ne
        // sert simplement pas encore. Rendre une erreur obligerait le client à
        // la recréer, et laisserait une ligne orpheline à chaque tentative.
        return ApiResponse::created($this->present($destination));
    }

    public function verify(Request $request, string $destinationId): JsonResponse
    {
        $destination = $this->find($request, $destinationId);

        $this->verifier->handle($destination);

        return ApiResponse::success($this->present($destination->refresh()));
    }

    public function credentials(Request $request, string $destinationId): JsonResponse
    {
        $validated = $request->validate(['credentials' => ['required', 'array']]);

        $destination = $this->find($request, $destinationId);
        $previous = $destination->credentials;

        $destination->forceFill(['credentials' => $validated['credentials']])->save();

        /*
         * L'épreuve tranche avant que les anciens identifiants ne soient
         * abandonnés : une rotation ratée ne doit pas couper le service.
         */
        if (! $this->verifier->handle($destination)) {
            $destination->forceFill(['credentials' => $previous])->save();
            $this->verifier->handle($destination);

            throw DomainException::conflict(
                'STORAGE_DESTINATION_UNVERIFIED',
                __('storage::messages.rotation_failed'),
            );
        }

        return ApiResponse::success($this->present($destination->refresh()));
    }

    public function update(Request $request, string $destinationId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,read_only,disabled'],
        ]);

        $destination = $this->find($request, $destinationId);

        /*
         * On ne rend pas `active` une destination qui n'a jamais rien prouvé :
         * ce serait contourner l'épreuve par la porte de service.
         */
        if ($validated['status'] === Destination::ACTIVE && $destination->verified_at === null) {
            throw DomainException::conflict(
                'STORAGE_DESTINATION_UNVERIFIED',
                __('storage::messages.activate_unverified'),
            );
        }

        $destination->forceFill(['status' => $validated['status']])->save();

        return ApiResponse::success($this->present($destination));
    }

    private function find(Request $request, string $id): Destination
    {
        $organizationId = $this->callerOrganizationId($request, self::DESTINATIONS);
        $destination = Destination::query()->find($id);

        if ($destination === null) {
            throw DomainException::notFound(
                'STORAGE_DESTINATION_NOT_FOUND',
                __('storage::messages.destination_not_found'),
            );
        }

        // Une destination de la plateforme ne s'administre pas depuis l'API :
        // elle est posée par `storage:destination`, à la main, par quelqu'un
        // qui a accès au serveur.
        if ($destination->owner_organization_id !== $organizationId) {
            throw DomainException::notFound(
                'STORAGE_DESTINATION_NOT_FOUND',
                __('storage::messages.destination_not_found'),
            );
        }

        return $destination;
    }

    /**
     * Les identifiants ne sortent jamais — pas même pour celui qui les a
     * déposés. Une empreinte suffit à reconnaître une clé ; elle ne suffit
     * jamais à s'en servir.
     *
     * @return array<string, mixed>
     */
    private function present(Destination $destination): array
    {
        return [
            'id' => (string) $destination->id,
            'slug' => $destination->slug,
            'driver' => $destination->driver,
            'preset' => $destination->preset,
            'config' => Arr::except((array) $destination->config, ['prefix']),
            'credential_fingerprint' => $destination->credentialFingerprint(),
            'environment' => $destination->environment,
            'status' => $destination->status,
            'is_default' => $destination->is_default,
            'verified_at' => $destination->verified_at?->toIso8601String(),
            'verification_reason' => $destination->verification_reason,
            'files' => StoredFile::query()
                ->where('destination_id', $destination->id)
                ->where('status', '<>', StoredFile::DELETED)
                ->count(),
        ];
    }
}
