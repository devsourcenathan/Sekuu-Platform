<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Les identifiants sont-ils valides, chez le vrai agrégateur ?
 *
 * ## La seule commande autorisée à toucher des identifiants de production
 *
 * `CredentialGuard` interdit de résoudre les agrégateurs quand l'environnement
 * et les identifiants ne s'accordent pas. Cette commande **ne les résout pas** :
 * elle lit la configuration et fait un appel en **lecture seule**.
 *
 * L'exemption est donc structurelle, pas déclarative. Elle ne peut pas débiter
 * qui que ce soit — il n'existe ici aucun chemin vers `charge()`.
 *
 * ## Ce qu'elle prouve, et ce qu'elle ne prouve pas
 *
 * Elle prouve que la clé est acceptée par l'agrégateur, et lequel des deux
 * environnements elle ouvre.
 *
 * Elle ne prouve **pas** que les callbacks arriveront : cela dépend de l'URL
 * enregistrée dans le tableau de bord, que seul un vrai paiement valide. C'est
 * pourquoi le sondage existe, et pourquoi il n'est pas optionnel.
 *
 * @see docs/06-operations/01-go-live.md
 */
final class VerifyCredentialsCommand extends Command
{
    protected $signature = 'payments:verify';

    protected $description = 'Vérifie que les identifiants des agrégateurs sont acceptés, sans déclencher aucun paiement.';

    public function handle(): int
    {
        $this->components->info('Aucun paiement n\'est déclenché : appels en lecture seule.');

        $lignes = [$this->tranzak(), $this->notchpay()];

        $this->table(['Agrégateur', 'Environnement', 'Identifiants', 'Détail'], $lignes);

        $echecs = array_filter($lignes, static fn (array $l): bool => str_starts_with($l[2], 'refusés'));

        if ($echecs !== []) {
            $this->newLine();
            $this->components->error('Des identifiants sont refusés. Ne déployez pas en l\'état.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->warn(
            'Les callbacks ne sont pas vérifiés ici : ils dépendent de l\'URL '
            .'enregistrée dans le tableau de bord, que seul un vrai paiement valide.'
        );

        return self::SUCCESS;
    }

    /**
     * Tranzak sépare ses environnements par l'**URL**. Obtenir un jeton prouve
     * que `appId` et `appKey` sont acceptés sur cet hôte.
     *
     * @return array{string, string, string, string}
     */
    private function tranzak(): array
    {
        $base = (string) config('payments.tranzak.base_url');
        $environnement = str_contains($base, 'sandbox') ? 'bac à sable' : '**production**';

        if (config('payments.tranzak.app_id') === null || config('payments.tranzak.app_id') === '') {
            return ['tranzak', $environnement, 'non configurés', 'Jamais essayé.'];
        }

        try {
            $reponse = Http::timeout(20)->acceptJson()->post(rtrim($base, '/').'/auth/token', [
                'appId' => config('payments.tranzak.app_id'),
                'appKey' => config('payments.tranzak.app_key'),
            ]);

            $corps = $reponse->json();

            // Une authentification refusée revient en HTTP 200 avec
            // `success: false` : c'est le corps qui fait autorité, pas le code.
            if ($reponse->failed() || ($corps['success'] ?? null) === false) {
                return ['tranzak', $environnement, 'refusés', (string) ($corps['errorMsg'] ?? 'HTTP '.$reponse->status())];
            }

            // Un jeton obtenu prouve que la cle **et** l'hote s'accordent :
            // verifie en conditions reelles, une cle de production est refusee
            // par l'hote de bac a sable. C'est ce qui rend une URL oubliee
            // bruyante plutot que silencieuse.
            return ['tranzak', $environnement, 'acceptés', 'Jeton obtenu sur cet hôte.'];
        } catch (Throwable $e) {
            return ['tranzak', $environnement, 'refusés', mb_substr($e->getMessage(), 0, 60)];
        }
    }

    /**
     * Notch Pay ne sépare pas ses environnements par l'URL : c'est le
     * **préfixe de la clé** qui décide. C'est toute la raison d'être de
     * `CredentialGuard`.
     *
     * @return array{string, string, string, string}
     */
    private function notchpay(): array
    {
        $cle = (string) config('payments.notchpay.public_key');
        $environnement = str_starts_with($cle, 'test_') ? 'bac à sable' : '**production**';

        if ($cle === '') {
            return ['notchpay', '—', 'non configurés', 'Jamais essayé.'];
        }

        try {
            // Lecture d'une liste, sans filtre qui écrive quoi que ce soit.
            $reponse = Http::timeout(20)
                ->acceptJson()
                ->withHeaders(['Authorization' => $cle])
                ->get(rtrim((string) config('payments.notchpay.base_url'), '/').'/payments');

            if ($reponse->status() === 401 || $reponse->status() === 403) {
                return ['notchpay', $environnement, 'refusés', 'HTTP '.$reponse->status()];
            }

            if ($reponse->failed()) {
                return ['notchpay', $environnement, 'incertains', 'HTTP '.$reponse->status()];
            }

            return ['notchpay', $environnement, 'acceptés', 'Lecture autorisée.'];
        } catch (Throwable $e) {
            return ['notchpay', $environnement, 'refusés', mb_substr($e->getMessage(), 0, 60)];
        }
    }
}
