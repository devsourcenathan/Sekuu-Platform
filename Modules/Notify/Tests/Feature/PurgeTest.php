<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationTemplate;
use Modules\Notify\Domain\Models\Suppression;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/02-data-model.md
 */
final class PurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_notifications_are_left_alone(): void
    {
        $this->makeNotification(now()->subDays(10));

        $this->artisan('notify:purge')->assertSuccessful();

        $this->assertSame(1, Notification::query()->count());
    }

    public function test_expired_notifications_are_purged(): void
    {
        $this->makeNotification(now()->subDays(400));
        $this->makeNotification(now()->subDays(10));

        $this->artisan('notify:purge')->assertSuccessful();

        $this->assertSame(1, Notification::query()->count());
    }

    /**
     * La purge supprime les notifications, pas l'histoire : sans agrégat,
     * douze mois de statistiques disparaîtraient avec elles.
     */
    public function test_statistics_survive_the_purge(): void
    {
        $this->makeNotification(now()->subDays(400));
        $this->makeNotification(now()->subDays(400));

        $this->artisan('notify:purge')->assertSuccessful();

        $this->assertSame(0, Notification::query()->count());

        $row = DB::table('notification_statistics')->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->total);
        $this->assertSame('email', $row->channel);
    }

    /**
     * Relancer la commande ne doit ni perdre ni doubler les compteurs.
     */
    public function test_running_twice_accumulates_without_duplicating(): void
    {
        $this->makeNotification(now()->subDays(400));
        $this->artisan('notify:purge')->assertSuccessful();

        $this->makeNotification(now()->subDays(400));
        $this->artisan('notify:purge')->assertSuccessful();

        $this->assertSame(1, DB::table('notification_statistics')->count());
        $this->assertSame(2, (int) DB::table('notification_statistics')->first()->total);
    }

    public function test_the_dry_run_deletes_nothing(): void
    {
        $this->makeNotification(now()->subDays(400));

        $this->artisan('notify:purge --dry-run')->assertSuccessful();

        $this->assertSame(1, Notification::query()->count());
        $this->assertSame(0, DB::table('notification_statistics')->count());
    }

    public function test_the_retention_can_be_overridden(): void
    {
        $this->makeNotification(now()->subDays(10));

        $this->artisan('notify:purge --days=5')->assertSuccessful();

        $this->assertSame(0, Notification::query()->count());
    }

    /**
     * Une adresse qui rebondit durablement ne redevient pas valide avec le
     * temps : la liste de suppression n'est jamais purgée.
     */
    public function test_suppressions_are_never_purged(): void
    {
        Suppression::create([
            'channel' => 'email',
            'destination' => 'disparu@sekuu.com',
            'reason' => Suppression::HARD_BOUNCE,
            'created_at' => now()->subYears(3),
        ]);

        $this->artisan('notify:purge')->assertSuccessful();

        $this->assertDatabaseCount('suppressions', 1);
    }

    private function makeNotification(\DateTimeInterface $createdAt): Notification
    {
        $template = NotificationTemplate::query()
            ->where('key', 'password.reset')
            ->where('channel', 'email')
            ->firstOrFail();

        $notification = Notification::create([
            'template_id' => $template->id,
            'template_key' => $template->key,
            'channel' => 'email',
            'category' => 'transactional',
            'locale' => 'fr',
            'recipient' => 'nathan@sekuu.com',
            'rendered_body' => 'corps',
            'status' => Notification::SENT,
        ]);

        // `created_at` est géré par Eloquent : il faut le forcer après coup.
        $notification->forceFill(['created_at' => $createdAt])->save();

        return $notification;
    }
}
