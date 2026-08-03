<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Notify\Domain\Models\Notification;

/**
 * Purge les notifications au-delà de la rétention, en conservant un agrégat.
 *
 * Sans agrégat, douze mois de statistiques disparaîtraient avec les lignes
 * purgées. Sans purge, la table croît indéfiniment.
 *
 * Les suppressions ne sont **jamais** purgées : une adresse qui rebondit
 * durablement ne redevient pas valide avec le temps.
 *
 * @see docs/03-services/notify/02-data-model.md
 */
final class PurgeNotificationsCommand extends Command
{
    protected $signature = 'notify:purge
        {--days= : Rétention en jours (défaut : config notify.retention.notifications_days)}
        {--dry-run : Compte sans rien supprimer}';

    protected $description = 'Purge les notifications expirées en conservant leurs statistiques';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('notify.retention.notifications_days'));
        $threshold = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $total = Notification::query()->where('created_at', '<', $threshold)->count();

        if ($total === 0) {
            $this->info('Aucune notification à purger.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d notification(s) antérieure(s) au %s.',
            $total,
            $threshold->toDateString(),
        ));

        if ($dryRun) {
            $this->comment('Mode simulation : rien n\'a été supprimé.');

            return self::SUCCESS;
        }

        $this->aggregate($threshold);
        $purged = $this->purge($threshold);

        $this->info(sprintf('%d notification(s) purgée(s), statistiques conservées.', $purged));

        return self::SUCCESS;
    }

    /**
     * L'agrégat est calculé **avant** la suppression, et cumulé : relancer la
     * commande ne doit ni perdre ni doubler les compteurs.
     */
    private function aggregate(\DateTimeInterface $threshold): void
    {
        $rows = Notification::query()
            ->where('created_at', '<', $threshold)
            ->selectRaw('DATE(created_at) as day, organization_id, channel, category, status, COUNT(*) as total')
            ->groupBy('day', 'organization_id', 'channel', 'category', 'status')
            ->get();

        foreach ($rows as $row) {
            $existing = DB::table('notification_statistics')
                ->where('day', $row->day)
                ->where('channel', $row->channel)
                ->where('category', $row->category)
                ->where('status', $row->status)
                ->when(
                    $row->organization_id === null,
                    fn ($q) => $q->whereNull('organization_id'),
                    fn ($q) => $q->where('organization_id', $row->organization_id),
                )
                ->first();

            if ($existing !== null) {
                DB::table('notification_statistics')
                    ->where('id', $existing->id)
                    ->update([
                        'total' => $existing->total + (int) $row->total,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('notification_statistics')->insert([
                'id' => (string) Str::uuid(),
                'day' => $row->day,
                'organization_id' => $row->organization_id,
                'channel' => $row->channel,
                'category' => $row->category,
                'status' => $row->status,
                'total' => (int) $row->total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Suppression par lots : une seule requête sur douze mois de volume
     * verrouillerait la table trop longtemps.
     */
    private function purge(\DateTimeInterface $threshold): int
    {
        $purged = 0;

        do {
            $batch = Notification::query()
                ->where('created_at', '<', $threshold)
                ->limit(1000)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            // Livraisons et événements tombent en cascade.
            $purged += Notification::query()->whereIn('id', $batch)->delete();
        } while (true);

        return $purged;
    }
}
