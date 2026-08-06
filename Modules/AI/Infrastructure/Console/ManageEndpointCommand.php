<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AI\Domain\Models\AiEndpoint;

/**
 * Déclare la destination des webhooks d'un produit, et fait tourner son secret.
 *
 * **Volontairement hors de l'API**, pour la même raison que côté paiement :
 * déclarer une destination sortante est une configuration permanente, du même
 * ordre qu'une règle de redirection de courrier. Quiconque peut la modifier peut
 * détourner l'issue de toutes les générations d'un produit vers un serveur de
 * son choix, et une clé d'API fuitée ne doit pas suffire à cela.
 *
 * @see docs/03-services/ai/07-external-api.md
 */
final class ManageEndpointCommand extends Command
{
    protected $signature = 'ai:endpoint
        {organization : Organisation proprietaire de la cle d API}
        {--url= : URL de livraison, https uniquement}
        {--rotate : Genere un nouveau secret en gardant l ancien valide}
        {--window=48 : Heures pendant lesquelles l ancien secret reste accepte}
        {--pause : Suspend les livraisons sans les perdre}
        {--resume : Reprend les livraisons}';

    protected $description = 'Déclare ou fait tourner l\'endpoint de livraison des événements d\'IA.';

    public function handle(): int
    {
        $organizationId = (string) $this->argument('organization');
        $endpoint = AiEndpoint::query()->where('organization_id', $organizationId)->first();

        if ($this->option('pause') || $this->option('resume')) {
            return $this->toggle($endpoint);
        }

        if ($endpoint === null) {
            return $this->create($organizationId);
        }

        if ($this->option('rotate')) {
            return $this->rotate($endpoint);
        }

        if ($this->option('url') !== null) {
            $endpoint->forceFill(['url' => $this->url()])->save();
            $this->info("URL mise à jour : {$endpoint->url}");

            return self::SUCCESS;
        }

        $this->line("URL      : {$endpoint->url}");
        $this->line("Statut   : {$endpoint->status}");
        $this->line('Rotation : '.($endpoint->previous_secret === null
            ? 'aucune en cours'
            : 'ancien secret valide jusqu\'au '.$endpoint->previous_secret_expires_at?->toDateTimeString()));

        return self::SUCCESS;
    }

    private function create(string $organizationId): int
    {
        if ($this->option('url') === null) {
            $this->error('Une URL est requise pour créer un endpoint.');

            return self::FAILURE;
        }

        $secret = $this->freshSecret();

        AiEndpoint::query()->create([
            'organization_id' => $organizationId,
            'url' => $this->url(),
            'secret' => $secret,
            'status' => AiEndpoint::ACTIVE,
        ]);

        return $this->reveal($secret, 'Endpoint créé.');
    }

    /**
     * Rotation sans coupure : les deux secrets signent, le temps que le produit
     * déploie le nouveau.
     */
    private function rotate(AiEndpoint $endpoint): int
    {
        $secret = $this->freshSecret();
        $hours = max(1, (int) $this->option('window'));

        $endpoint->forceFill([
            'previous_secret' => $endpoint->secret,
            'previous_secret_expires_at' => now()->addHours($hours),
            'secret' => $secret,
        ])->save();

        return $this->reveal(
            $secret,
            "Secret renouvelé. L'ancien reste accepté {$hours} h — chaque livraison porte les deux signatures.",
        );
    }

    private function toggle(?AiEndpoint $endpoint): int
    {
        if ($endpoint === null) {
            $this->error('Aucun endpoint pour cette organisation.');

            return self::FAILURE;
        }

        $paused = (bool) $this->option('pause');

        $endpoint->forceFill([
            'status' => $paused ? AiEndpoint::PAUSED : AiEndpoint::ACTIVE,
        ])->save();

        $this->info($paused
            ? 'Livraisons suspendues. Elles s\'accumulent, elles ne se perdent pas.'
            : 'Livraisons reprises.');

        return self::SUCCESS;
    }

    /**
     * `https` obligatoire.
     *
     * La signature prouve l'origine, pas la confidentialité — et la charge porte
     * l'identifiant d'une génération, qui suffit à venir chercher sa sortie.
     */
    private function url(): string
    {
        $url = (string) $this->option('url');

        if (! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('L\'URL de livraison doit être en https.');
        }

        return $url;
    }

    private function freshSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    /**
     * La valeur n'est affichée qu'ici — elle n'est jamais relisible par l'API.
     */
    private function reveal(string $secret, string $message): int
    {
        $this->info($message);
        $this->newLine();
        $this->line("  {$secret}");
        $this->newLine();
        $this->comment('Transmettez-le hors bande. Il ne sera plus affiché.');

        return self::SUCCESS;
    }
}
