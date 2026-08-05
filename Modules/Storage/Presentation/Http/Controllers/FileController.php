<?php

declare(strict_types=1);

namespace Modules\Storage\Presentation\Http\Controllers;

use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\FileRef;
use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storage\Application\Files\ConfirmFile;
use Modules\Storage\Application\Files\DeclareFile;
use Modules\Storage\Application\Files\DeleteFile;
use Modules\Storage\Application\Files\IssueReadUrl;
use Modules\Storage\Application\Files\OwnerRegistry;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Presentation\Http\Concerns\ResolvesFileActor;
use Modules\Storage\Presentation\Http\Requests\DeclareFileRequest;

/**
 * Déclarer, confirmer, lire, supprimer.
 *
 * @see docs/03-services/storage/03-api.md
 */
final class FileController
{
    use ResolvesFileActor;

    public function __construct(private readonly OwnerRegistry $owners) {}

    public function store(DeclareFileRequest $request, DeclareFile $declare): JsonResponse
    {
        $declared = $declare->handle(
            owner: new FileRef(
                $request->string('owner_type')->toString(),
                $request->string('owner_id')->toString(),
            ),
            actor: $this->actor($request, self::WRITE),
            name: $request->string('name')->toString(),
            mimeType: $request->string('mime_type')->toString(),
            size: $request->integer('size'),
            destination: $request->has('destination') ? $request->string('destination')->toString() : null,
            retainDays: $request->has('retain_days') ? $request->integer('retain_days') : null,
        );

        return ApiResponse::created([
            'id' => (string) $declared->file->id,
            'status' => $declared->file->status,
            'upload_url' => $declared->ticket->url,

            // Ce que le pilote répond, jamais une supposition. Un client qui
            // suppose `PUT` casse le jour où le magasin change, sans que rien
            // ne l'ait prévenu.
            'upload_method' => $declared->ticket->method,
            'upload_headers' => $declared->ticket->headers,
            'direct_upload' => $declared->directUpload,
            'expires_at' => $declared->ticket->expiresAt->format(DATE_ATOM),
        ]);
    }

    public function confirm(Request $request, ConfirmFile $confirm, string $fileId): JsonResponse
    {
        $file = $confirm->handle($this->find($fileId), $this->actor($request, self::WRITE));

        return ApiResponse::success($this->present($file));
    }

    public function show(Request $request, string $fileId): JsonResponse
    {
        $file = $this->find($fileId);
        $actor = $this->actor($request, self::READ);

        // Meme cloisonnement que la delivrance d'URL : sans lui, un identifiant
        // devine renseignerait sur le nom et la taille d'un document d'autrui.
        if (! $this->readable($file, $actor)) {
            throw DomainException::notFound('FILE_NOT_FOUND', __('storage::messages.file_not_found'));
        }

        return ApiResponse::success($this->present($file));
    }

    /**
     * `owner_type` et `owner_id` sont **requis**.
     *
     * Sans eux la question deviendrait « tous les fichiers de mon
     * organisation », et il faudrait définir ce que voit un membre simple d'une
     * organisation qui en compte trois cents. Ce module ne peut pas répondre :
     * il ignore les rôles. Avec eux, la question redevient « les fichiers de
     * cette facture », et le propriétaire sait y répondre.
     */
    public function index(Request $request, IssueReadUrl $urls): JsonResponse
    {
        $validated = $request->validate([
            'owner_type' => ['required', 'string', 'max:64'],
            'owner_id' => ['required', 'string', 'max:64'],
        ]);

        $actor = $this->actor($request, self::READ);
        $ref = new FileRef($validated['owner_type'], $validated['owner_id']);

        $files = StoredFile::query()
            ->where('owner_type', $ref->type)
            ->where('owner_id', $ref->id)
            ->where('status', StoredFile::READY)
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (StoredFile $file): bool => $this->readable($file, $actor))
            ->values();

        return ApiResponse::success($files->map(fn (StoredFile $file): array => $this->present($file))->all());
    }

    public function url(Request $request, IssueReadUrl $urls, string $fileId): JsonResponse
    {
        $file = $this->find($fileId);
        $issued = $urls->handle($file, $this->actor($request, self::READ), $request->ip());

        return ApiResponse::success([
            'url' => $issued->url,
            'expires_at' => $issued->expiresAt->toIso8601String(),
            'disposition' => $issued->disposition,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
        ]);
    }

    public function destroy(Request $request, DeleteFile $delete, string $fileId): JsonResponse
    {
        $delete->handle($this->find($fileId), $this->actor($request, self::WRITE));

        return ApiResponse::noContent();
    }

    /**
     * Un identifiant inconnu et un fichier hors de portée rendent la même
     * chose : distinguer transformerait la route en oracle.
     */
    private function find(string $fileId): StoredFile
    {
        $file = StoredFile::query()->with('destination')->find($fileId);

        if ($file === null) {
            throw DomainException::notFound('FILE_NOT_FOUND', __('storage::messages.file_not_found'));
        }

        return $file;
    }

    /**
     * Un type devenu inconnu — un module retiré de la configuration — ne fait
     * pas échouer un listing : ses fichiers en disparaissent, ce qui est le
     * comportement sûr. Les faire apparaître supposerait de savoir qui a le
     * droit de les lire, et plus personne ne peut répondre.
     */
    private function readable(StoredFile $file, FileActor $actor): bool
    {
        try {
            return $this->owners->for($file->owner_type)->mayRead($file->owner(), $actor);
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * `path` et `destination_id` n'y sont pas.
     *
     * La clé porte l'identifiant d'organisation : la rendre publierait la
     * structure du compartiment et inviterait à deviner celle du voisin. Un
     * client manipule un `id` et reçoit des URL signées.
     *
     * @return array<string, mixed>
     */
    private function present(StoredFile $file): array
    {
        return [
            'id' => (string) $file->id,
            'owner_type' => $file->owner_type,
            'owner_id' => $file->owner_id,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'checksum' => $file->checksum,
            'status' => $file->status,
            'retain_until' => $file->retain_until?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
            'confirmed_at' => $file->confirmed_at?->toIso8601String(),
        ];
    }
}
