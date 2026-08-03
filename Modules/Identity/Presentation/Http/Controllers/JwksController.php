<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Identity\Infrastructure\Jwt\SigningKeys;

/**
 * Clés publiques de vérification des access tokens.
 *
 * Ce document est public par nature : il ne contient que des clés publiques,
 * et c'est ce qui permet aux produits de valider un token sans détenir de
 * secret. Il n'utilise pas l'enveloppe de réponse de la plateforme, car le
 * format JWKS est normalisé (RFC 7517).
 *
 * @see docs/02-standards/security.md
 */
final class JwksController
{
    public function __invoke(SigningKeys $keys): JsonResponse
    {
        return new JsonResponse(
            ['keys' => [$keys->toJwk()]],
            headers: ['Cache-Control' => 'public, max-age=3600'],
        );
    }
}
