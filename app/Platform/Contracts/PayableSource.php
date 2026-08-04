<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Le propriétaire d'un objet payable.
 *
 * Implémenté par le module qui possède l'objet — Billing pour une facture, un
 * produit pour une commande — et résolu par `subject_type`. La couche de
 * paiement ne connaît aucune implémentation : elle les résout par
 * configuration, dans l'esprit du registre d'agrégateurs.
 *
 * Trois méthodes, et la première porte tout le poids.
 *
 * @see docs/05-analyses/extraction-payments.md
 */
interface PayableSource
{
    /**
     * Combien faut-il encaisser, et ce payeur y est-il autorisé ?
     *
     * **Sans effet de bord et idempotente** : appelée à chaque demande
     * d'encaissement, y compris sur un objet déjà réglé.
     */
    public function quote(PayableRef $ref, PayerContext $payer): PayableQuote;

    /**
     * Le paiement a abouti.
     *
     * Appelée **dans la transaction** d'encaissement, et non par un événement :
     * confier ce moment à une file créerait une fenêtre où l'argent est
     * encaissé et le service fermé — qu'un consommateur en échec définitif
     * rendrait permanente. C'est précisément la défaillance que ce module
     * existe pour empêcher.
     *
     * Doit être **idempotente** : le même règlement peut arriver deux fois.
     */
    public function settled(PaymentSettlement $settlement): void;

    /**
     * Le paiement a échoué définitivement.
     *
     * Existe pour que le propriétaire puisse prévenir son client dans ses
     * propres termes — et publier ses propres événements. Sans elle, renommer
     * un événement de paiement ferait disparaître un message sans qu'aucune
     * erreur ne le signale.
     */
    public function failed(PaymentSettlement $settlement): void;
}
