<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use App\Platform\Contracts\BillingContract;
use App\Platform\Contracts\PlanLimit;
use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Tests\Concerns\UsesApiKey;
use Tests\TestCase;

/**
 * Le quota de canal vient du **plan** ; le plafond de dépense de la
 * configuration de la plateforme.
 *
 * Les deux coexistent sans se remplacer : le premier est une limite
 * commerciale, le second un garde-fou contre l'emballement. Un plan illimité
 * n'échappe pas au second.
 *
 * @see docs/03-services/billing/01-overview.md
 */
final class ChannelQuotaTest extends TestCase
{
    use RefreshDatabase;
    use UsesApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Une passerelle SMS est nécessaire pour que le canal existe.
        config()->set('notify.sms.local_gateway.endpoint', 'https://passerelle.test/envoi');
        config()->set('notify.sms.local_gateway.token', 'jeton');
    }

    public function test_a_send_is_refused_once_the_plan_quota_is_reached(): void
    {
        $this->issueKey(['notifications.send']);
        $this->limitSms(1);

        $this->send('+237650000000');

        $this->expectException(DomainException::class);

        try {
            $this->send('+237650000001');
        } catch (DomainException $e) {
            $this->assertSame('QUOTA_EXCEEDED', $e->errorCode);
            $this->assertSame(429, $e->status);

            throw $e;
        }
    }

    /**
     * Un plan illimité ne borne pas le canal.
     */
    public function test_an_unlimited_plan_does_not_cap_the_channel(): void
    {
        $this->issueKey(['notifications.send']);
        $this->limit(PlanLimit::unlimited());

        $this->send('+237650000000');
        $outcome = $this->send('+237650000001');

        $this->assertTrue($outcome);
    }

    /**
     * Une organisation sans abonnement n'est pas bloquée : un quota borne un
     * usage autorisé, il ne décide pas de l'autorisation.
     */
    public function test_an_organisation_without_a_plan_is_not_capped(): void
    {
        $this->issueKey(['notifications.send']);
        $this->limit(PlanLimit::noSubscription());

        $this->assertTrue($this->send('+237650000000'));
    }

    /**
     * Compter les messages **acceptés**, et non les livraisons : sinon un envoi
     * groupé franchirait le quota avant qu'aucun message n'ait abouti.
     */
    public function test_queued_messages_already_count(): void
    {
        $this->issueKey(['notifications.send']);
        $this->limitSms(2);

        $this->send('+237650000000');
        $this->send('+237650000001');

        $this->assertDatabaseCount('notifications', 2);

        $this->expectException(DomainException::class);
        $this->send('+237650000002');
    }

    private function limitSms(int $value): void
    {
        $this->limit(PlanLimit::of($value));
    }

    private function limit(PlanLimit $limit): void
    {
        $this->app->bind(BillingContract::class, fn () => new class($limit) implements BillingContract
        {
            public function __construct(private readonly PlanLimit $limit) {}

            public function limit(string $organizationId, string $key): PlanLimit
            {
                return $key === 'sms_monthly' ? $this->limit : PlanLimit::unlimited();
            }
        });
    }

    private function send(string $phone): bool
    {
        return $this->app->make(SendNotification::class)->handle(new SendRequest(
            templateKey: 'password.changed',
            recipients: [Channel::SMS => $phone],
            variables: ['first_name' => 'Nathan', 'changed_at' => '3 août 2026'],
            organizationId: $this->organizationId,
        ))->sentAnything();
    }
}
