<?php

declare(strict_types=1);

namespace Modules\Storage\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Infrastructure\Drivers\LocalDriver;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le magasin local, servi par la plateforme elle-même.
 *
 * **Seulement pour le développement et les tests.** En production aucune
 * destination `local` n'est éligible : l'environnement d'une destination doit
 * correspondre à celui de l'application, et le garde-fou le vérifie.
 *
 * L'accès repose entièrement sur la signature de l'URL, hors de la pile
 * d'authentification de l'API. C'est délibéré et fidèle : une URL présignée S3
 * fonctionne exactement ainsi — une capacité valable pour un objet et une
 * durée, et non un droit attaché à qui la présente.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class LocalObjectController
{
    public function __construct(private readonly LocalDriver $driver) {}

    public function put(Request $request, string $destinationId, string $path): Response
    {
        $destination = $this->destination($destinationId);

        $this->driver->disk($destination)->put($this->decode($path), $request->getContent());

        return new Response('', 200);
    }

    public function get(Request $request, string $destinationId, string $path): StreamedResponse
    {
        $destination = $this->destination($destinationId);
        $disk = $this->driver->disk($destination);
        $key = $this->decode($path);

        if (! $disk->exists($key)) {
            throw DomainException::notFound('FILE_NOT_FOUND', __('storage::messages.file_not_found'));
        }

        return $disk->download($key);
    }

    private function destination(string $id): Destination
    {
        $destination = Destination::query()->find($id);

        if ($destination === null || $destination->driver !== 'local') {
            throw DomainException::notFound(
                'STORAGE_DESTINATION_NOT_FOUND',
                __('storage::messages.destination_not_found'),
            );
        }

        return $destination;
    }

    /**
     * La clé voyage encodée dans l'URL, et la signature la couvre : un chemin
     * modifié invalide la signature avant que ce code ne s'exécute.
     *
     * Le contrôle sur `..` est malgré tout conservé. Une seconde borne coûte
     * une ligne, et l'histoire des services de fichiers est faite de signatures
     * qu'on croyait couvrir ce qu'elles ne couvraient pas.
     */
    private function decode(string $encoded): string
    {
        $path = (string) base64_decode($encoded, true);

        if ($path === '' || str_contains($path, '..')) {
            throw DomainException::notFound('FILE_NOT_FOUND', __('storage::messages.file_not_found'));
        }

        return $path;
    }
}
