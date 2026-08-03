<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résout la langue de la réponse.
 *
 * Ordre de priorité :
 *
 *   1. `Accept-Language`, si la langue demandée est supportée
 *   2. la langue par défaut de la plateforme
 *
 * La préférence enregistrée de l'utilisateur — le claim `lang` du token —
 * s'applique ensuite, à la résolution de l'authentification : elle n'est
 * connue qu'à ce moment-là, et elle **prime** sur l'en-tête. Un utilisateur
 * qui a choisi le français ne doit pas recevoir de l'anglais parce que son
 * navigateur envoie `Accept-Language: en`.
 *
 * @see docs/02-standards/api-guidelines.md
 */
final class ResolveLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $this->fromHeader($request);

        if ($requested !== null) {
            App::setLocale($requested);
        }

        $response = $next($request);

        // Le client doit savoir dans quelle langue on lui a répondu : sans cet
        // en-tête, un cache intermédiaire servirait la mauvaise version.
        $response->headers->set('Content-Language', App::getLocale());
        $response->headers->set('Vary', 'Accept-Language', false);

        return $response;
    }

    /**
     * Parcourt `Accept-Language` par qualité décroissante, et retient la
     * première langue supportée. `fr-CA` est accepté comme `fr` : la région
     * ne change pas la traduction.
     */
    private function fromHeader(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language', '');

        if ($header === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';q=', trim($part));
            $tag = mb_strtolower(trim($segments[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $candidates[] = [
                'language' => explode('-', $tag)[0],
                'quality' => isset($segments[1]) ? (float) $segments[1] : 1.0,
            ];
        }

        usort($candidates, static fn (array $a, array $b) => $b['quality'] <=> $a['quality']);

        foreach ($candidates as $candidate) {
            if (self::isSupported($candidate['language'])) {
                return $candidate['language'];
            }
        }

        return null;
    }

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, (array) config('sekuu.locales', []), true);
    }
}
