<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Concerns;

use Modules\Identity\Application\ApiKeys\IssueApiKey;
use Modules\Identity\Domain\Models\GlobalRole;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\Organization;
use Modules\Identity\Domain\Models\User;

trait UsesApiKey
{
    protected string $organizationId;

    protected User $apiKeyOwner;

    /**
     * @param  list<string>  $scopes
     */
    protected function issueKey(array $scopes, string $email = 'nathan@sekuu.com'): string
    {
        if (! isset($this->apiKeyOwner)) {
            $this->apiKeyOwner = User::create([
                'first_name' => 'Nathan',
                'last_name' => 'Tchinda',
                'email' => $email,
            ]);

            $organization = Organization::create(['name' => 'SOS Clinique', 'slug' => 'sos-clinique']);
            $this->organizationId = $organization->id;

            $membership = Membership::create([
                'user_id' => $this->apiKeyOwner->id,
                'organization_id' => $organization->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);
            $membership->roles()->attach(GlobalRole::query()->where('slug', 'owner')->firstOrFail()->id);
        }

        return $this->app->make(IssueApiKey::class)->handle(
            organizationId: $this->organizationId,
            name: 'Tests '.implode(',', $scopes),
            scopes: $scopes,
            creator: $this->apiKeyOwner,
        )->plainKey;
    }
}
