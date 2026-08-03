<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Notify\Infrastructure\Providers\ProviderRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La suite ne doit jamais emprunter les identifiants réels du `.env`.
 *
 * Ce test existe parce que l'oubli s'est produit : le jour où une clé Resend
 * a été renseignée, la suite a envoyé une centaine de vrais messages. La
 * protection reposait jusque-là sur le fait qu'aucune clé n'était configurée,
 * ce qui n'est pas une protection.
 */
final class TestEnvironmentIsolationTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function credentials(): array
    {
        return [
            'Resend (envoi)' => ['notify.email.resend.api_key', 'RESEND_API_KEY'],
            'Resend (webhook)' => ['notify.email.resend.webhook_secret', 'RESEND_WEBHOOK_SECRET'],
            'Postmark (envoi)' => ['notify.email.postmark.server_token', 'POSTMARK_SERVER_TOKEN'],
            'Postmark (webhook)' => ['notify.email.postmark.webhook_token', 'POSTMARK_WEBHOOK_TOKEN'],
            'Passerelle SMS' => ['notify.sms.local_gateway.token', 'SMS_GATEWAY_TOKEN'],
            'Passerelle SMS (webhook)' => ['notify.sms.local_gateway.webhook_secret', 'SMS_GATEWAY_WEBHOOK_SECRET'],
            // Un identifiant de paiement réel ne produirait pas un message de
            // trop : il produirait un débit sur le compte de quelqu'un.
            'Tranzak (identifiant)' => ['billing.tranzak.app_id', 'TRANZAK_APP_ID'],
            'Tranzak (clé)' => ['billing.tranzak.app_key', 'TRANZAK_APP_KEY'],
            'Tranzak (callback)' => ['billing.tranzak.auth_key', 'TRANZAK_AUTH_KEY'],
            'Twilio' => ['notify.sms.twilio.token', 'TWILIO_TOKEN'],
        ];
    }

    #[DataProvider('credentials')]
    public function test_no_real_provider_credential_leaks_into_the_test_environment(
        string $key,
        string $variable,
    ): void {
        $this->assertEmpty(
            config($key),
            "{$variable} est renseigné pendant les tests. Neutralisez-le dans phpunit.xml : "
                .'sans quoi la suite appellera le vrai fournisseur et enverra de vrais messages.',
        );
    }

    /**
     * Le mailer doit rester en mémoire : même sans fournisseur externe, un
     * driver SMTP configuré ferait sortir des messages.
     */
    public function test_the_mailer_never_reaches_the_outside(): void
    {
        $this->assertContains(config('mail.default'), ['array', 'log']);
    }

    /**
     * Aucun canal ne doit disposer d'un fournisseur réel : la chaîne email se
     * réduit au mailer local, et le SMS n'a aucun fournisseur.
     */
    public function test_no_channel_is_backed_by_a_real_provider(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);

        $email = array_map(fn ($p) => $p->name(), $registry->forChannel('email'));

        $this->assertSame(['laravel-mail'], $email);
        $this->assertFalse($registry->hasChannel('sms'));
    }
}
