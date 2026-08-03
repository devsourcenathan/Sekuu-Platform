<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Workspaces;

use App\Platform\Exceptions\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\Workspace;
use Modules\Identity\Domain\Models\WorkspaceMember;

final class CreateWorkspace
{
    /**
     * @param  array{name: string, slug?: string|null, settings?: array<string, mixed>|null}  $attributes
     */
    public function handle(Membership $creator, array $attributes): Workspace
    {
        return DB::transaction(function () use ($creator, $attributes): Workspace {
            try {
                $workspace = Workspace::create([
                    'organization_id' => $creator->organization_id,
                    'name' => $attributes['name'],
                    'slug' => $attributes['slug'] ?? Str::slug($attributes['name']),
                    'settings' => $attributes['settings'] ?? [],
                ]);
            } catch (QueryException $e) {
                if (self::isUniqueViolation($e)) {
                    throw DomainException::conflict(
                        'DUPLICATE_RESOURCE',
                        __('identity::messages.workspace_slug_taken'),
                    );
                }

                throw $e;
            }

            // Le créateur devient membre : sans cela il ne verrait pas le
            // workspace qu'il vient de créer.
            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'membership_id' => $creator->id,
                'is_default' => ! WorkspaceMember::query()
                    ->where('membership_id', $creator->id)
                    ->where('is_default', true)
                    ->exists(),
            ]);

            return $workspace;
        });
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
