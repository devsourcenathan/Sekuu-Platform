<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Providers;

use RuntimeException;

/**
 * L'environnement et les identifiants doivent être d'accord.
 *
 * ## Les deux fautes, opposées et toutes deux coûteuses
 *
 * **Une clé de production hors production** fait partir de vrais débits sur de
 * vrais téléphones depuis un poste de développement. Chez Notch Pay, rien ne
 * l'empêche : `api.notchpay.co` sert le test et la production, et c'est le
 * **préfixe de la clé** qui décide. Un copier-coller suffit.
 *
 * **Une clé de test en production** est le miroir, et se voit encore moins :
 * les paiements « aboutissent » sans qu'aucun argent ne bouge. Le client reçoit
 * une confirmation, le service s'ouvre, et la plateforme n'a rien encaissé.
 * Personne ne le signale — jusqu'au rapprochement bancaire.
 *
 * ## Pourquoi ce garde-fou échoue durement
 *
 * Ce dépôt a déjà envoyé une centaine de vrais emails le jour où une clé a été
 * renseignée : la protection reposait sur le fait qu'aucune clé n'était
 * configurée, ce qui n'est pas une protection. `phpunit.xml` neutralise
 * désormais les identifiants pour la suite de tests — mais le développement
 * manuel restait exposé.
 *
 * Il n'existe **aucune variable pour désactiver ce contrôle**. Une échappatoire
 * finit toujours par être activée « juste pour essayer », et c'est exactement
 * l'essai qui débite quelqu'un.
 *
 * @see docs/06-operations/01-go-live.md
 */
final class CredentialGuard
{
    /** Une clé Notch Pay de test porte ce préfixe. Toute autre est réelle. */
    private const NOTCHPAY_TEST_PREFIX = 'test_';

    /** L'hôte de bac à sable de Tranzak, seul à ne pas mouvoir d'argent. */
    private const TRANZAK_SANDBOX = 'sandbox';

    /**
     * @throws RuntimeException si la configuration ne correspond pas à l'environnement
     */
    public static function assert(string $environment): void
    {
        $production = $environment === 'production';

        foreach (self::inconsistencies($production) as $message) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @return list<string>
     */
    private static function inconsistencies(bool $production): array
    {
        $issues = [];

        $notchpay = (string) config('payments.notchpay.public_key');

        if ($notchpay !== '') {
            $test = str_starts_with($notchpay, self::NOTCHPAY_TEST_PREFIX);

            if (! $production && ! $test) {
                $issues[] = self::message(
                    'NOTCHPAY_PUBLIC_KEY est une clé de production, et APP_ENV ne l\'est pas.',
                    'Un paiement partirait réellement, sur le téléphone d\'une vraie personne. '
                    .'Notch Pay ne distingue pas ses environnements par l\'URL : seul le préfixe '
                    .'`test_` le fait.',
                );
            }

            if ($production && $test) {
                $issues[] = self::message(
                    'NOTCHPAY_PUBLIC_KEY est une clé de test, en production.',
                    'Les paiements aboutiraient sans qu\'aucun argent ne soit encaissé — le '
                    .'client verrait son service ouvert, et la plateforme n\'aurait rien reçu.',
                );
            }
        }

        $tranzak = (string) config('payments.tranzak.base_url');
        $appId = (string) config('payments.tranzak.app_id');

        if ($appId !== '' && $tranzak !== '') {
            $sandbox = str_contains($tranzak, self::TRANZAK_SANDBOX);

            if (! $production && ! $sandbox) {
                $issues[] = self::message(
                    'TRANZAK_BASE_URL pointe sur la production, et APP_ENV ne l\'est pas.',
                    'Un paiement partirait réellement.',
                );
            }

            if ($production && $sandbox) {
                $issues[] = self::message(
                    'TRANZAK_BASE_URL pointe sur le bac à sable, en production.',
                    'Les paiements aboutiraient sans encaissement réel.',
                );
            }
        }

        return $issues;
    }

    private static function message(string $observation, string $consequence): string
    {
        return implode(' ', [
            '[Payments]',
            $observation,
            $consequence,
            'Corrigez APP_ENV ou les identifiants — il n\'existe pas d\'option pour ignorer ce contrôle.',
            'Voir docs/06-operations/01-go-live.md.',
        ]);
    }
}
