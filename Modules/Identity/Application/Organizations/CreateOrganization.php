<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Organizations;

use App\Platform\Exceptions\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\GlobalRole;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\Organization;
use Modules\Identity\Domain\Models\User;

final class CreateOrganization
{
    /**
     * @param  array{name: string, slug?: string|null, country?: string|null, currency?: string|null, timezone?: string|null, locale?: string|null}  $attributes
     */
    public function handle(User $creator, array $attributes): Organization
    {
        return DB::transaction(function () use ($creator, $attributes): Organization {
            try {
                $organization = Organization::create([
                    'name' => $attributes['name'],
                    'slug' => $attributes['slug'] ?? Str::slug($attributes['name']),
                    'country' => $attributes['country'] ?? null,
                    'currency' => $attributes['currency'] ?? null,
                    'timezone' => $attributes['timezone'] ?? 'UTC',
                    'locale' => $attributes['locale'] ?? $creator->language ?? 'fr',
                ]);
            } catch (QueryException $e) {
                if (str_contains(strtolower($e->getMessage()), 'unique') || $e->getCode() === '23505') {
                    throw DomainException::conflict(
                        'ORGANIZATION_SLUG_TAKEN',
                        __('This organization slug is already taken.'),
                    );
                }

                throw $e;
            }

            $membership = Membership::create([
                'user_id' => $creator->id,
                'organization_id' => $organization->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            // Le créateur devient owner : une organisation doit toujours
            // conserver au moins un propriétaire.
            $owner = GlobalRole::query()->where('slug', GlobalRole::OWNER)->firstOrFail();
            $membership->roles()->attach($owner->id);

            return $organization;
        });
    }
}
