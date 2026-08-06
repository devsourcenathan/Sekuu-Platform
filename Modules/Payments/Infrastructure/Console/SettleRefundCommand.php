<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use App\Platform\Support\Money;
use Illuminate\Console\Command;
use Modules\Payments\Application\Refunds\SettleRefund;
use Modules\Payments\Domain\Models\Refund;

/**
 * Le geste humain.
 *
 * ## Pourquoi c'est une commande et non un appel à un agrégateur
 *
 * Un remboursement Mobile Money est un **décaissement**, pas l'annulation d'un
 * débit. Il exige un solde disponible sur le compte marchand, une API de
 * transfert distincte de celle d'encaissement, et il échoue pour des raisons
 * qui n'ont rien à voir avec le paiement d'origine.
 *
 * Aucun adaptateur de décaissement n'existe, et aucun compte marchand de
 * production non plus. En écrire un sans bac à sable reproduirait exactement
 * l'erreur du canal SMS de Notify — intégralement écrit, jamais exécuté contre
 * une vraie passerelle. Sur de l'argent qui **sort**, la facture serait plus
 * salée.
 *
 * Le module enregistre donc l'obligation ; un opérateur vire, puis vient le
 * constater ici avec la référence du transfert. C'est ce que l'ADR-0007 décrit
 * déjà : « un geste décidé par un humain, pas une mécanique du module ».
 *
 * @see docs/03-services/payments/08-refunds.md
 */
final class SettleRefundCommand extends Command
{
    protected $signature = 'payments:refund
        {refund? : Identifiant du remboursement a constater}
        {--reference= : Reference du transfert chez l\'operateur}
        {--provider= : Operateur ayant execute le transfert}
        {--fail= : Constate un echec, avec son motif}
        {--cancel= : Annule avant tout decaissement, avec son motif}';

    protected $description = 'Liste les remboursements en attente, et constate un décaissement déjà effectué à la main.';

    public function handle(SettleRefund $settle): int
    {
        $id = $this->argument('refund');

        if ($id === null) {
            return $this->listPending();
        }

        $refund = Refund::query()->find($id);

        if ($refund === null) {
            $this->error('Remboursement inconnu.');

            return self::FAILURE;
        }

        if ($refund->isSettled()) {
            $this->warn("Déjà tranché : {$refund->status}. Rien n'a été fait.");

            return self::SUCCESS;
        }

        if ($reason = $this->option('fail')) {
            $settle->failed($refund, 'REFUND_TRANSFER_FAILED', (string) $reason);
            $this->info('Échec constaté. La somme redevient remboursable.');

            return self::SUCCESS;
        }

        if ($reason = $this->option('cancel')) {
            $settle->cancelled($refund, (string) $reason);
            $this->info("Annulé. Aucun argent n'a bougé.");

            return self::SUCCESS;
        }

        /*
         * La référence du transfert est **obligatoire**.
         *
         * C'est la seule pièce qui relie une ligne de registre à un mouvement
         * réel sur le compte marchand. Sans elle, un rapprochement bancaire ne
         * peut pas conclure, et un remboursement conteste devient indéfendable.
         */
        $reference = $this->option('reference');

        if ($reference === null) {
            $this->error('La référence du transfert est requise pour constater un décaissement.');

            return self::FAILURE;
        }

        $settle->succeeded($refund, $this->option('provider'), (string) $reference);

        // « Constaté », jamais « rendu ». Cette commande n'envoie aucun argent :
        // elle enregistre qu'un virement a eu lieu. Une formulation qui laisse
        // croire au decaissement produit exactement la faute qu'on veut eviter —
        // un registre qui dit qu'un argent est sorti alors qu'il est reste.
        $this->info(sprintf(
            'Décaissement de %s constaté, référence %s.',
            $refund->money()->format(),
            (string) $reference,
        ));
        $this->comment("Cette commande n'envoie pas d'argent : elle enregistre un virement déjà fait.");
        $this->comment('Le registre porte désormais la ligne, et le produit est prévenu.');

        return self::SUCCESS;
    }

    /**
     * Sans argument, la commande répond à la question qu'un opérateur se pose
     * réellement : qu'est-ce que je dois virer aujourd'hui ?
     */
    private function listPending(): int
    {
        $pending = Refund::query()
            ->whereIn('status', [Refund::PENDING, Refund::PROCESSING])
            ->orderBy('created_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Aucun remboursement en attente.');

            return self::SUCCESS;
        }

        $this->table(
            ['Identifiant', 'Montant', 'Objet', 'Motif', 'Décidé le'],
            $pending->map(fn (Refund $r): array => [
                $r->id,
                Money::of($r->amount, $r->currency)->format(),
                $r->subject_type.':'.mb_substr($r->subject_id, 0, 8),
                mb_substr($r->reason, 0, 40),
                $r->created_at?->toDateTimeString(),
            ])->all(),
        );

        $this->newLine();
        $this->comment('Constater : payments:refund <id> --reference=<ref-du-transfert>');

        return self::SUCCESS;
    }
}
