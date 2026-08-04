<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use Modules\Payments\Infrastructure\Providers\CredentialGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * L'environnement et les identifiants doivent être d'accord, **dans les deux
 * sens**.
 *
 * Ce test existe pour la même raison que `TestEnvironmentIsolationTest` : ce
 * dépôt a déjà envoyé une centaine de vrais messages le jour où une clé a été
 * renseignée. La protection reposait sur le fait qu'aucune clé n'était
 * configurée, ce qui n'est pas une protection.
 *
 * @see docs/06-operations/01-go-live.md
 */
final class CredentialGuardTest extends TestCase
{
    /**
     * La faute qui débite de vraies personnes depuis un poste de développement.
     */
    public function test_a_live_notchpay_key_outside_production_is_refused(): void
    {
        config()->set('payments.notchpay.public_key', 'pk_live_quelque_chose');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NOTCHPAY_PUBLIC_KEY est une clé de production');

        CredentialGuard::assert('local');
    }

    /**
     * Le miroir, qui se voit encore moins : les paiements aboutissent sans
     * qu'aucun argent ne soit encaissé.
     */
    public function test_a_test_notchpay_key_in_production_is_refused(): void
    {
        config()->set('payments.notchpay.public_key', 'test_quelque_chose');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('clé de test');

        CredentialGuard::assert('production');
    }

    public function test_a_test_key_outside_production_is_accepted(): void
    {
        config()->set('payments.notchpay.public_key', 'test_quelque_chose');

        CredentialGuard::assert('local');

        $this->expectNotToPerformAssertions();
    }

    public function test_a_live_key_in_production_is_accepted(): void
    {
        config()->set('payments.notchpay.public_key', 'pk_live_quelque_chose');
        config()->set('payments.tranzak.base_url', 'https://dsapi.tranzak.me');

        CredentialGuard::assert('production');

        $this->expectNotToPerformAssertions();
    }

    /**
     * Tranzak sépare ses environnements par l'URL, pas par la clé.
     */
    public function test_the_tranzak_production_host_outside_production_is_refused(): void
    {
        config()->set('payments.tranzak.app_id', 'un-identifiant');
        config()->set('payments.tranzak.base_url', 'https://dsapi.tranzak.me');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TRANZAK_BASE_URL pointe sur la production');

        CredentialGuard::assert('local');
    }

    public function test_the_tranzak_sandbox_in_production_is_refused(): void
    {
        config()->set('payments.tranzak.app_id', 'un-identifiant');
        config()->set('payments.tranzak.base_url', 'https://sandbox.dsapi.tranzak.me');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bac à sable');

        CredentialGuard::assert('production');
    }

    /**
     * Une URL sans identifiants ne prouve rien : c'est le défaut de
     * `config/payments.php`, et un agrégateur non configuré n'est jamais
     * essayé.
     */
    public function test_an_unconfigured_aggregator_is_never_a_problem(): void
    {
        config()->set('payments.notchpay.public_key', '');
        config()->set('payments.tranzak.app_id', '');
        config()->set('payments.tranzak.base_url', 'https://dsapi.tranzak.me');

        CredentialGuard::assert('local');
        CredentialGuard::assert('production');

        $this->expectNotToPerformAssertions();
    }

    /**
     * La suite de tests elle-même doit passer ce contrôle : `phpunit.xml`
     * neutralise les identifiants, donc rien n'est configuré.
     */
    public function test_the_test_environment_passes_its_own_guard(): void
    {
        CredentialGuard::assert('testing');

        $this->expectNotToPerformAssertions();
    }
}
