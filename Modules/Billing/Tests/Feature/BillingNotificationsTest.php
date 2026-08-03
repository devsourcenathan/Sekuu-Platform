<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Application\Payments\InitiatePayment;
use Modules\Billing\Application\Subscriptions\AdvanceLifecycle;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;
use Modules\Billing\Infrastructure\Providers\ChargeOutcome;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Billing\Tests\Support\FakeProvider;
use Modules\Identity\Domain\Models\User;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationTemplate;
use Tests\TestCase;

/**
 * Billing publiait ses rappels dans le vide : aucune correspondance côté
 * Notify, aucun template. Un client voyait son accès se fermer sans avoir rien
 * reçu.
 *
 * C'est le pilier de l'ADR-0007 — la plateforme ne peut pas prélever, donc la
 * seule chose qu'elle puisse faire pour être payée est de prévenir.
 *
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
final class BillingNotificationsTest extends TestCase
{
    use BillsAnOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProviders();
        $this->signInAsOwner();

        // Le propriétaire a un numéro **dans tous les tests**. Sans cela, le
        // SMS serait absent partout, et les tests de limitation prouveraient
        // seulement qu'on ne peut pas envoyer — pas qu'on choisit de ne pas
        // envoyer.
        // La langue du contact est celle du **destinataire**, pas celle de la
        // requête qui a déclenché l'événement : une tâche planifiée n'a pas de
        // requête, et personne ne choisit la langue d'un rappel à sa place.
        User::query()
            ->where('email', 'nathan@sekuu.com')
            ->update(['phone' => '+237650000000', 'language' => 'fr']);
    }

    public function test_issuing_an_invoice_writes_to_the_owner(): void
    {
        $invoice = $this->subscribe('business');

        $notification = $this->notificationFor('invoice.issued');

        $this->assertSame('nathan@sekuu.com', $notification->recipient);
        $this->assertStringContainsString($invoice->number, $notification->rendered_subject);

        // Le montant est rendu lisible, pas brut : 178 875 XAF, pas « 178875 ».
        $this->assertStringContainsString('178 875 XAF', $notification->rendered_body);
    }

    /**
     * Une facture déjà réglée ne vaut pas un message : personne n'a rien à
     * payer.
     */
    public function test_a_zero_invoice_produces_no_message(): void
    {
        $this->subscribe('clinic-pro');

        $this->assertNull($this->notificationFor('invoice.issued'));
    }

    public function test_a_successful_payment_produces_a_receipt(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 178875));

        $invoice = $this->subscribe('business');
        $this->app->make(InitiatePayment::class)->handle($invoice, '+237650000000');

        $this->assertNotNull($this->notificationFor('invoice.paid'));
        $this->assertNotNull($this->notificationFor('subscription.activated'));
    }

    /**
     * Le SMS n'accompagne que le dernier rappel. Trois SMS par mois et par
     * organisation coûteraient plus cher que le service qu'ils protègent.
     */
    public function test_only_the_last_reminder_is_also_sent_by_sms(): void
    {
        $this->prepareReminder(days: 7);
        $this->app->make(AdvanceLifecycle::class)->handle();

        $this->assertNotNull($this->notificationFor('subscription.expiring', 'email'));
        $this->assertNull($this->notificationFor('subscription.expiring', 'sms'));
    }

    public function test_the_final_reminder_carries_an_sms(): void
    {
        $this->prepareReminder(days: 1);
        $this->app->make(AdvanceLifecycle::class)->handle();

        $this->assertNotNull($this->notificationFor('subscription.expiring', 'email'));

        $sms = $this->notificationFor('subscription.expiring', 'sms');

        $this->assertNotNull($sms);
        $this->assertSame('+237650000000', $sms->recipient);

        // Un SMS est facturé par tranche de 160 caractères.
        $this->assertLessThanOrEqual(160, mb_strlen($sms->rendered_body));
    }

    /**
     * Le moment où le modèle prépayé exige une action : la période est échue,
     * l'accès va fermer. Email **et** SMS.
     */
    public function test_entering_grace_warns_on_both_channels(): void
    {
        $subscription = $this->activeSubscription();
        $this->expirePeriod($subscription);

        $this->app->make(AdvanceLifecycle::class)->handle();

        $this->assertNotNull($this->notificationFor('subscription.grace', 'email'));
        $this->assertNotNull($this->notificationFor('subscription.grace', 'sms'));
    }

    /**
     * Pas de SMS : l'accès est déjà fermé. Le SMS sert à faire agir avant, pas
     * à constater après.
     */
    public function test_suspension_is_announced_by_email_only(): void
    {
        $subscription = $this->activeSubscription();

        $subscription->forceFill([
            'status' => SubscriptionStatus::Grace,
            'grace_ends_at' => now()->subHour(),
        ])->save();

        $this->app->make(AdvanceLifecycle::class)->handle();

        $this->assertNotNull($this->notificationFor('subscription.suspended', 'email'));
        $this->assertNull($this->notificationFor('subscription.suspended', 'sms'));
    }

    public function test_a_failed_payment_warns_on_both_channels(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::failed('PAYMENT_FAILED', 'Solde insuffisant'));

        $invoice = $this->subscribe('business');
        $this->app->make(InitiatePayment::class)->handle($invoice, '+237650000000');

        $email = $this->notificationFor('payment.failed', 'email');

        $this->assertNotNull($email);
        $this->assertNotNull($this->notificationFor('payment.failed', 'sms'));

        // Rassurer explicitement : le client qui vient d'échouer craint d'avoir
        // été débité quand même.
        $this->assertStringContainsString('Rien n\'a été débité', $email->rendered_body);
    }

    /**
     * Aucun de ces messages n'est désactivable. Un client qui aurait coupé les
     * notifications de facturation n'aurait pas exercé un choix : il aurait
     * perdu l'information dont il a besoin pour agir.
     */
    public function test_every_billing_message_is_transactional(): void
    {
        $categories = NotificationTemplate::query()
            ->whereIn('key', [
                'subscription.activated', 'subscription.expiring', 'subscription.grace',
                'subscription.suspended', 'invoice.issued', 'invoice.paid', 'payment.failed',
            ])
            ->pluck('category')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['transactional'], $categories);
    }

    // ------------------------------------------------------------ fixtures --

    private function activeSubscription(): Subscription
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 178875));

        $invoice = $this->subscribe('business');
        $this->app->make(InitiatePayment::class)->handle($invoice, '+237650000000');

        Notification::query()->delete();

        return Subscription::query()->firstOrFail();
    }

    private function prepareReminder(int $days): void
    {
        $subscription = $this->activeSubscription();

        $subscription->forceFill([
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays($days)->startOfDay()->addHours(6),
        ])->save();
    }

    private function notificationFor(string $key, string $channel = 'email'): ?Notification
    {
        return Notification::query()
            ->where('template_key', $key)
            ->where('channel', $channel)
            ->first();
    }
}
