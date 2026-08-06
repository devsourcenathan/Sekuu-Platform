<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Middleware;

use App\Platform\Contracts\PlatformContext;
use App\Platform\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Application\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le garde de `/api/v1/platform/…`.
 *
 * ## Il journalise les lectures, pas seulement les écritures
 *
 * C'est inhabituel, et c'est la contrepartie qui rend le reste acceptable.
 *
 * Un opérateur qui consulte la facture d'un client accède à une donnée qui ne
 * lui appartient pas. Si cet accès ne laisse pas de trace, la seule garantie
 * offerte au client est notre parole.
 *
 * Le journal est écrit **après** la réponse, et porte son statut : une tentative
 * refusée mérite autant d'être tracée qu'un accès réussi — c'est même la
 * première chose qu'on cherchera le jour d'un incident.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 */
final class RequiresPlatformOperator
{
    public function __construct(
        private readonly PlatformContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $authorised = $this->context->may($permission);

        if (! $authorised) {
            $this->record($request, $permission, 403);

            /*
             * `403`, et non `404`.
             *
             * La règle « ne jamais distinguer inexistant de pas-à-vous » vaut
             * pour les ressources d'un client. Ici il n'y a rien à cacher :
             * l'existence de l'administration de la plateforme n'est pas un
             * secret, et prétendre que la route n'existe pas ferait perdre du
             * temps à un opérateur mal habilité.
             */
            throw DomainException::forbidden(
                'PLATFORM_ACCESS_DENIED',
                __('identity::messages.platform_access_denied'),
            );
        }

        $response = $next($request);

        $this->record($request, $permission, $response->getStatusCode());

        return $response;
    }

    private function record(Request $request, string $permission, int $status): void
    {
        $this->audit->record(
            action: 'platform.'.mb_strtolower($request->method()),
            payload: [
                'permission' => $permission,
                'path' => $request->path(),
                'status' => $status,
                'operator_id' => $this->context->operatorId(),

                // Jamais le corps de la requête : une modification de limites
                // est déjà journalisée par le cas d'usage, avec l'avant et
                // l'après. Le recopier ici doublerait la donnée sans rien
                // ajouter.
            ],
        );
    }
}
