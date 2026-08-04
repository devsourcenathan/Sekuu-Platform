<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Contracts;

use App\Platform\Contracts\RequestContext;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\JwtUserResolver;

/**
 * Implémentation locale de `RequestContext`, adossée à l'access token.
 *
 * Lié **par requête** et non en singleton : un contexte partagé fuirait d'une
 * requête à l'autre — la même raison pour laquelle `JwtUserResolver` mémoïse
 * par `spl_object_id` plutôt que dans le conteneur.
 */
final readonly class JwtRequestContext implements RequestContext
{
    public function __construct(
        private JwtUserResolver $resolver,
        private Request $request,
    ) {}

    public function organizationId(): ?string
    {
        return $this->resolver->require($this->request)->token->organizationId;
    }

    public function userId(): string
    {
        return $this->resolver->require($this->request)->token->userId;
    }

    public function hasAnyRole(array $roles): bool
    {
        $token = $this->resolver->require($this->request)->token;

        foreach ($roles as $role) {
            if ($token->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
