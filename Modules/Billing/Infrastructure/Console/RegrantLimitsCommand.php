<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Billing\Application\Plans\GrantLimits;
use Modules\Billing\Domain\Models\Subscription;

/**
 * Remplir ou corriger les limites accordées.
 *
 * Sert deux cas. Les abonnements créés **avant** que la copie n'existe, qui
 * n'en ont pas. Et le rattrapage après une correction de catalogue, quand on
 * veut vérifier que tout le monde a bien ce qu'il doit avoir.
 *
 * ## Elle respecte l'asymétrie, sauf si on le lui interdit
 *
 * Par défaut, elle ne fait que des hausses — comme une modification de plan. Un
 * outil de rattrapage qui baisserait silencieusement reprendrait à des clients
 * ce qu'ils ont payé, et personne ne le verrait passer.
 *
 * `--force` applique la copie intégrale, baisses comprises. Réservé à une
 * correction délibérée, et la commande le dit avant de le faire.
 *
 * @see docs/04-decisions/adr-0019-granted-limits.md
 */
final class RegrantLimitsCommand extends Command
{
    protected $signature = 'billing:regrant
        {--force : Applique aussi les baisses, ce qui reprend ce qui a ete promis}
        {--dry-run : Montre ce qui changerait, sans rien ecrire}';

    protected $description = 'Réapplique les limites du catalogue aux abonnements en cours.';

    public function handle(GrantLimits $grant): int
    {
        $sec = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $subscriptions = Subscription::query()->alive()->with('plan')->get();

        if ($subscriptions->isEmpty()) {
            $this->info('Aucun abonnement vivant.');

            return self::SUCCESS;
        }

        if ($force && ! $sec) {
            $this->warn('--force applique les baisses : des clients perdront ce qui leur a été promis');
            $this->warn('pour la période en cours, avant son terme.');
            $this->newLine();
        }

        $rows = [];
        $changed = 0;

        foreach ($subscriptions as $subscription) {
            $catalogue = (array) ($subscription->plan?->limits ?? []);
            $granted = (array) ($subscription->granted_limits ?? []);

            $after = $force ? $catalogue : $this->raisesOnly($granted, $catalogue);

            if ($after === $granted) {
                continue;
            }

            $changed++;
            $rows[] = [
                mb_substr((string) $subscription->organization_id, 0, 8),
                $subscription->plan?->key ?? '—',
                $this->summarise($granted),
                $this->summarise($after),
            ];

            if ($sec) {
                continue;
            }

            $subscription->forceFill([
                'granted_limits' => $after,
                'limits_granted_at' => now(),
            ])->save();
        }

        if ($rows !== []) {
            $this->table(['Organisation', 'Plan', 'Avant', 'Après'], $rows);
        }

        $this->info(sprintf(
            '%s : %d abonnement(s) sur %d %s.',
            $sec ? 'Simulation' : 'Rattrapage',
            $changed,
            $subscriptions->count(),
            $sec ? 'changeraient' : 'mis à jour',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $granted
     * @param  array<string, mixed>  $catalogue
     * @return array<string, mixed>
     */
    private function raisesOnly(array $granted, array $catalogue): array
    {
        $after = $granted;

        foreach ($catalogue as $key => $value) {
            if (! array_key_exists($key, $granted)) {
                $after[$key] = $value;

                continue;
            }

            $current = $granted[$key];

            // `null` vaut illimité : on n'en descend jamais, et on y monte
            // toujours.
            if ($current === null) {
                continue;
            }

            if ($value === null || (int) $value > (int) $current) {
                $after[$key] = $value;
            }
        }

        return $after;
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function summarise(array $limits): string
    {
        if ($limits === []) {
            return '(vide)';
        }

        $pairs = [];

        foreach ($limits as $key => $value) {
            $pairs[] = $key.'='.($value === null ? '∞' : $value);
        }

        return implode(' ', $pairs);
    }
}
